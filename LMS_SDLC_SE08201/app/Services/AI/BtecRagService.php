<?php

namespace App\Services\AI;

// =========================================================================
// KHAI BÁO THƯ VIỆN & MODEL PHỤC VỤ HYBRID RAG
// =========================================================================
use App\Models\BtecKbEmbedding; // Model Eloquent kết nối bảng `btec_kb_embeddings` trong MySQL lưu trữ vector 1024/3072 chiều của 35 chunks quy chế
use App\Models\User;            // Model Eloquent người dùng (sinh viên) để cá nhân hóa ngữ cảnh truy vấn theo lịch học/điểm danh thực tế

/**
 * BTEC FPT RAG SERVICE
 * Quản lý kho tri thức (Knowledge Base) và thuật ngữ quy chế BTEC FPT + Hướng dẫn ứng dụng Smart LMS.
 * Truy xuất đoạn tri thức bằng embedding vector (cosine) khi đã đánh chỉ mục,
 * tự động fallback sang khớp từ khóa nếu chưa có chỉ mục hoặc không gọi được API.
 */
class BtecRagService
{
    private array $sources = [];
    private array $chunks = [];
    private array $procedures = [];
    private array $faqs = [];
    private array $appFeatures = [];

    /** Ngưỡng điểm từ khóa để coi là "tìm đúng tài liệu" (đã hiệu chỉnh trên bộ 30 FAQ + câu ngoài phạm vi). */
    private const LEXICAL_CONFIDENT = 30;

    /** T01 = phạm vi & cách đọc tài liệu, T22 = danh mục nguồn: chỉ dùng để tham chiếu. */
    private const META_TOPICS = ['T01', 'T22'];

    /** Chỉ mục từ khóa theo chunk: tokens, bigrams, tiêu đề/nhãn mục. */
    private array $lexIndex = [];
    private array $df = [];
    private int $nSections = 1;
    private ?array $lastFaq = null;

    /** Cách truy xuất dùng ở lần search gần nhất: VECTOR_HYBRID hoặc LEXICAL. */
    private string $lastRetrievalMethod = 'LEXICAL';
    private float $lastTopScore = 0.0;
    private bool $lastIsConfident = false;

    public function __construct(
        private readonly ?GeminiEmbeddingService $embeddingService = null
    ) {
        $this->loadKnowledgeBase();
        $this->initAppFeatures();
    }

    /**
     * PHẦN 1 TRONG PIPELINE RAG: NẠP TẦNG DỮ LIỆU TRI THỨC (KNOWLEDGE BASE)
     * Đọc 4 cấu trúc dữ liệu từ thư mục storage/btec_ai_kb/:
     *  1) sources.json: Danh mục 16 văn bản pháp lý/quyết định gốc (S01..S16) để trích dẫn nguồn.
     *  2) kb_chunks.jsonl: 35 đoạn văn bản quy chế (chunks) đã được cắt nhỏ theo từng điều khoản.
     *  3) procedures.json: Các bước hướng dẫn thủ tục hành chính (bảo lưu, phúc tra, khôi phục điểm danh).
     *  4) faq_seed.json: Bộ câu hỏi thường gặp (FAQ).
     */
    private function loadKnowledgeBase(): void
    {
        $kbDir = storage_path('btec_ai_kb');

        // 1. Tải danh mục nguồn chính thức
        if (file_exists("$kbDir/sources.json")) {
            $raw = json_decode(file_get_contents("$kbDir/sources.json"), true);
            foreach ($raw ?: [] as $s) {
                $this->sources[$s['source_id']] = $s;
            }
        }

        // 2. Tải các đoạn tri thức: đọc từ MySQL (bảng btec_kb_chunks do Admin quản lý),
        //    bảng trống thì tự nạp từ file kb_chunks.jsonl; lỗi DB thì đọc tạm từ file.
        $this->chunks = app(KbChunkStore::class)->all();

        // 3. Tải quy trình thủ tục (procedures.json)
        if (file_exists("$kbDir/procedures.json")) {
            $this->procedures = json_decode(file_get_contents("$kbDir/procedures.json"), true) ?: [];
        }

        // 4. Tải FAQ (faq_seed.json)
        if (file_exists("$kbDir/faq_seed.json")) {
            $this->faqs = json_decode(file_get_contents("$kbDir/faq_seed.json"), true) ?: [];
        }
    }

    /**
     * Khởi tạo bản đồ tri thức ứng dụng Smart LMS (App Knowledge Base).
     */
    private function initAppFeatures(): void
    {
        $this->appFeatures = [
            [
                'feature_id' => 'APP_SCHEDULE',
                'name' => 'Thời khóa biểu & Lịch học',
                'route' => '/student/schedule',
                'icon' => '🗓️',
                'keywords' => ['lịch học', 'thời khóa biểu', 'ca học', 'phòng học', 'lịch tuần', 'tkb', 'hôm nay học gì'],
                'description' => 'Xem toàn bộ lịch học theo tuần và theo năm. Cho biết ca học (Ca 1 đến Ca 4), phòng học cụ thể và giảng viên đứng lớp.',
                'steps' => [
                    'Bấm vào mục "🗓️ Thời khóa biểu" trên thanh menu bên trái (hoặc bấm nút mở bên dưới).',
                    'Chọn Năm học và Tuần học cần xem ở thanh trên cùng.',
                    'Quan sát bảng lịch học từ Thứ 2 đến Chủ nhật, nhấp vào từng ô để xem chi tiết phòng học và ca học.'
                ]
            ],
            [
                'feature_id' => 'APP_ATTENDANCE',
                'name' => 'Lịch sử Điểm danh & Chuyên cần',
                'route' => '/student/attendance',
                'icon' => '✅',
                'keywords' => ['điểm danh', 'chuyên cần', 'nghỉ học', 'vắng mặt', 'đi học muộn', 'đến lớp muộn', 'tỷ lệ chuyên cần', 'cấm thi'],
                'description' => 'Xem lịch sử điểm danh chi tiết từng buổi học, thống kê Có mặt (P), Vắng (A), Muộn (L) và tỷ lệ chuyên cần tổng hợp.',
                'steps' => [
                    'Bấm vào mục "✅ Lịch sử điểm danh" trên thanh menu bên trái.',
                    'Xem 4 thẻ thống kê ở đầu trang (Tổng số buổi, Có mặt, Vắng mặt, Tỷ lệ chuyên cần).',
                    'Có thể dùng ô chọn "Lọc theo Lớp học" để kiểm tra riêng từng môn hoặc xem tổng hợp toàn khóa.'
                ]
            ],
            [
                'feature_id' => 'APP_ASSIGNMENTS',
                'name' => 'Bài tập & Quiz trực tuyến',
                'route' => '/student/assignments',
                'icon' => '📝',
                'keywords' => ['bài tập', 'assignment', 'asm', 'quiz', 'trắc nghiệm', 'nộp bài', 'hạn nộp', 'deadline', 'nộp bài tập'],
                'description' => 'Xem danh sách bài tập được giao, thời hạn nộp bài, làm bài trắc nghiệm (Quiz) và nộp tệp bài làm tự luận.',
                'steps' => [
                    'Truy cập mục "📝 Bài tập & Quiz" trên menu.',
                    'Chọn bài tập cần làm: Nếu là Quiz bấm "Làm bài trắc nghiệm", nếu là Assignment bấm "Xem chi tiết".',
                    'Đính kèm tệp bài làm (Word, PDF, ZIP...) hoặc điền câu trả lời và nhấn nút "Nộp bài".'
                ]
            ],
            [
                'feature_id' => 'APP_GRADES',
                'name' => 'Bảng điểm & Kết quả học tập',
                'route' => '/student/grades',
                'icon' => '🏆',
                'keywords' => ['bảng điểm', 'điểm số', 'kết quả', 'gpa', 'điểm tổng kết', 'điểm thi', 'điểm môn'],
                'description' => 'Xem điểm quá trình, điểm assignment, điểm chuyên cần và điểm tổng kết của tất cả các môn học đã tham gia.',
                'steps' => [
                    'Nhấp vào mục "🏆 Bảng điểm" trên thanh menu.',
                    'Xem bảng tổng hợp điểm theo từng học kỳ và môn học.',
                    'Quan sát cột Trạng thái để biết môn học đã Đạt (Passed) hay Cần thi lại/Học lại (Retake).'
                ]
            ],
            [
                'feature_id' => 'APP_MATERIALS',
                'name' => 'Kho Học liệu & Giáo trình',
                'route' => '/student/materials',
                'icon' => '📚',
                'keywords' => ['học liệu', 'tài liệu', 'giáo trình', 'slide', 'bài giảng', 'sách', 'tải tài liệu'],
                'description' => 'Tải xuống slide bài giảng, đề cương chi tiết và tài liệu học tập chính thức do giảng viên bộ môn cung cấp.',
                'steps' => [
                    'Mở mục "📚 Học liệu" từ menu.',
                    'Chọn môn học cần tải tài liệu.',
                    'Nhấn vào nút "Tải về" cạnh mỗi giáo trình hoặc slide bài giảng.'
                ]
            ],
            [
                'feature_id' => 'APP_PROFILE',
                'name' => 'Hồ sơ cá nhân & Đổi mật khẩu',
                'route' => '/profile',
                'icon' => '👤',
                'keywords' => ['đổi mật khẩu', 'mật khẩu', 'password', 'hồ sơ', 'thông tin cá nhân', 'avatar', 'tài khoản'],
                'description' => 'Xem thông tin sinh viên, mã số sinh viên, cập nhật ảnh đại diện và thay đổi mật khẩu đăng nhập an toàn.',
                'steps' => [
                    'Chọn "👤 Hồ sơ cá nhân" ở góc dưới thanh menu bên trái.',
                    'Cuộn xuống phần "Cập nhật mật khẩu".',
                    'Nhập mật khẩu hiện tại, mật khẩu mới và xác nhận mật khẩu mới, sau đó nhấn "Lưu thay đổi".'
                ]
            ],
            [
                'feature_id' => 'APP_AI_TUTOR',
                'name' => 'MR. BIẾT TUỐT (Gia sư học tập)',
                'route' => '/student/ai-tutor',
                'icon' => '🧙‍♂️',
                'keywords' => ['mr biết tuốt', 'thầy biết tuốt', 'ai tutor', 'gia sư', 'hỏi bài', 'soi code', 'luyện tập'],
                'description' => 'Gia sư AI học thuật MR. BIẾT TUỐT hỗ trợ giải thích bản chất kiến thức lập trình, gợi ý hướng giải bài tập và soi lỗi code 24/7.',
                'steps' => [
                    'Nhấp vào mục "🧙‍♂️ MR. BIẾT TUỐT" trên menu.',
                    'Bấm "Cuộc trò chuyện mới", chọn môn học và chế độ cần hỗ trợ (Giải thích, Hướng dẫn bài tập, Soi lỗi code, Luyện tập).',
                    'Nhập câu hỏi hoặc đính kèm ảnh/file code (tối đa 5MB) để Thầy hướng dẫn.'
                ]
            ],
            [
                'feature_id' => 'APP_NOTIFICATIONS',
                'name' => 'Thông báo nhà trường',
                'route' => '/student/notifications',
                'icon' => '🔔',
                'keywords' => ['thông báo', 'tin tức', 'lịch thi', 'nhắc nhở'],
                'description' => 'Xem toàn bộ các thông báo mới từ Phòng Đào tạo, Phòng CTSV và nhắc nhở bài tập sắp hạn.',
                'steps' => [
                    'Nhấp vào biểu tượng "🔔 Thông báo" trên thanh menu.',
                    'Bấm vào từng thông báo để đọc chi tiết nội dung và các mốc thời gian quan trọng.'
                ]
            ]
        ];
    }

    /**
     * Nhận diện ý định (Intent Classification).
     */
    public function classifyIntent(string $query): string
    {
        $q = mb_strtolower($query);

        // Escalation / Human support
        if (str_contains($q, 'gặp nhân viên') || str_contains($q, 'gặp người') || str_contains($q, 'khiếu nại') || str_contains($q, 'phúc tra') || str_contains($q, 'sai điểm') || str_contains($q, 'hỗ trợ trực tiếp') || str_contains($q, 'tạo ticket')) {
            return 'HUMAN_SUPPORT';
        }

        // App Navigation & Troubleshooting
        foreach ($this->appFeatures as $feat) {
            foreach ($feat['keywords'] as $kw) {
                if (str_contains($q, $kw)) {
                    if (str_contains($q, 'làm sao') || str_contains($q, 'ở đâu') || str_contains($q, 'xem thế nào') || str_contains($q, 'hướng dẫn') || str_contains($q, 'đổi mật khẩu') || str_contains($q, 'trên app') || str_contains($q, 'chỗ nào')) {
                        return 'APP_NAVIGATION';
                    }
                }
            }
        }

        if (str_contains($q, 'lỗi') || str_contains($q, 'không vào được') || str_contains($q, 'không đăng nhập') || str_contains($q, 'quên mật khẩu') || str_contains($q, 'không tải được')) {
            return 'APP_TROUBLESHOOTING';
        }

        // Financial
        if (str_contains($q, 'học phí') || str_contains($q, 'đóng tiền') || str_contains($q, 'ngân hàng') || str_contains($q, 'chuyển khoản') || str_contains($q, 'hoàn phí') || str_contains($q, 'học bổng') || str_contains($q, 'nợ phí') || str_contains($q, 'tiền học')) {
            return 'FINANCIAL_SUPPORT';
        }

        // Academic & Assignment
        if (str_contains($q, 'assignment') || str_contains($q, 'asm') || str_contains($q, 'đạo văn') || str_contains($q, 'nộp muộn') || str_contains($q, 'resubmission') || str_contains($q, 'retake') || str_contains($q, 'thi lại') || str_contains($q, 'học lại')) {
            return 'ACADEMIC_SUPPORT';
        }

        // Attendance & Regulations
        if (str_contains($q, 'nghỉ') || str_contains($q, 'cấm thi') || str_contains($q, 'chuyên cần') || str_contains($q, 'điểm danh') || str_contains($q, '20%') || str_contains($q, 'bảo lưu') || str_contains($q, 'thôi học') || str_contains($q, 'nội quy') || str_contains($q, 'đồng phục')) {
            return 'REGULATION_QUESTION';
        }

        // Admission
        if (str_contains($q, 'tuyển sinh') || str_contains($q, 'nhập học') || str_contains($q, 'xét tuyển') || str_contains($q, 'ielts') || str_contains($q, 'hồ sơ')) {
            return 'ADMISSION_SUPPORT';
        }

        // Contact
        if (str_contains($q, 'phòng đào tạo') || str_contains($q, 'ctsv') || str_contains($q, 'liên hệ') || str_contains($q, 'số điện thoại') || str_contains($q, 'email')) {
            return 'STUDENT_SERVICE';
        }

        return 'GENERAL_QUESTION';
    }

    /**
     * Tìm kiếm Hybrid Search kết hợp: Knowledge Base + Procedures + App Knowledge.
     */
    public function search(string $query, ?User $student = null): array
    {
        $intent = $this->classifyIntent($query);
        $cleanQuery = mb_strtolower(trim($query));
        $terms = array_filter(explode(' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $cleanQuery)));

        // 1. Tìm trong App Features — CHỈ khớp khi câu hỏi không phải về Học phí, Tuyển sinh, Bảo lưu hay Khiếu nại hành chính
        $nonAppIntents = ['FINANCIAL_SUPPORT', 'ADMISSION_SUPPORT', 'STUDENT_SERVICE', 'HUMAN_SUPPORT'];
        $isNonAppTopic = in_array($intent, $nonAppIntents, true)
            || (bool) preg_match('/(học phí|tiền học|đóng tiền|nộp tiền|công nợ|bảo lưu|thôi học|tuyển sinh|nhập học|phúc khảo|phúc tra)/u', $cleanQuery);

        $matchedAppFeatures = [];
        if (! $isNonAppTopic) {
            foreach ($this->appFeatures as $feat) {
                $score = 0;
                foreach ($feat['keywords'] as $kw) {
                    if (str_contains($cleanQuery, $kw)) {
                        $score += 15;
                    }
                }
                if ($score > 0) {
                    $matchedAppFeatures[] = ['feature' => $feat, 'score' => $score];
                }
            }
            usort($matchedAppFeatures, fn ($a, $b) => $b['score'] <=> $a['score']);
        }

        // 2. Truy xuất Knowledge Chunks: chỉ lấy tối đa 2 đoạn sát nhất để tránh nhiễu ngữ cảnh và nguồn trích dẫn rác
        $topChunks = $this->retrieveChunks($query, $cleanQuery, $intent, $terms);

        // 3. Tìm trong Procedures
        $matchedProcedures = [];
        foreach ($this->procedures as $proc) {
            $score = 0;
            $pText = mb_strtolower($proc['title'] . ' ' . implode(' ', $proc['suggested_steps'] ?? []));
            foreach ($terms as $term) {
                if (mb_strlen($term) >= 2 && str_contains($pText, $term)) {
                    $score += 5;
                }
            }
            if ($score > 0) {
                $matchedProcedures[] = ['proc' => $proc, 'score' => $score];
            }
        }
        usort($matchedProcedures, fn ($a, $b) => $b['score'] <=> $a['score']);
        $topProcedures = array_slice(array_column($matchedProcedures, 'proc'), 0, 1);

        // 4. Thu thập nguồn trích dẫn (Citations) — CHỈ lấy từ đoạn khớp số 1 (tối đa 2 nguồn sát nhất, không gom nguồn rác)
        $citations = [];
        $collectedSourceIds = [];
        $primaryChunk = $topChunks[0] ?? null;
        if ($primaryChunk) {
            foreach (array_slice($primaryChunk['source_refs'] ?? [], 0, 2) as $sId) {
                if (! in_array($sId, $collectedSourceIds, true) && isset($this->sources[$sId])) {
                    $collectedSourceIds[] = $sId;
                    $src = $this->sources[$sId];
                    $citations[] = [
                        'source_id' => $src['source_id'],
                        'title' => $src['title'],
                        'url' => $src['url'] ?? '',
                        'class' => $src['source_class'] ?? 'btec_public',
                    ];
                }
            }
        }

        // 5. Chuẩn bị nút điều hướng màn hình — CHỈ gắn khi thực sự liên quan đến tính năng có trên LMS
        $actions = [];
        if (! empty($matchedAppFeatures)) {
            $bestFeature = $matchedAppFeatures[0]['feature'];
            $actions[] = [
                'label' => 'Mở màn hình ' . $bestFeature['name'],
                'url' => $bestFeature['route'],
                'icon' => $bestFeature['icon'],
            ];
        }

        $needsTicket = ! $this->lastIsConfident
            || in_array($intent, ['HUMAN_SUPPORT', 'FINANCIAL_SUPPORT', 'STUDENT_SERVICE'], true)
            || (bool) preg_match('/(khiếu nại|phúc tra|phúc khảo|bảo lưu|thôi học|học phí|tiền học|gia hạn|khôi phục điểm danh)/u', $cleanQuery);

        return [
            'intent' => $intent,
            'retrieval_method' => $this->lastRetrievalMethod,
            'top_score' => round($this->lastTopScore, 2),
            'is_confident' => $this->lastIsConfident,
            'top_chunks' => $topChunks,
            'top_procedures' => $topProcedures,
            'top_app_feature' => ! empty($matchedAppFeatures) ? $matchedAppFeatures[0]['feature'] : null,
            'citations' => $this->lastIsConfident ? $citations : [],
            'actions' => $actions,
            'needs_escalation' => $needsTicket,
            // FAQ đã biên soạn khớp với câu hỏi (kèm hướng dẫn trả lời an toàn) hoặc null
            'faq' => $this->lastFaq,
            // Các đoạn (mục) sát câu hỏi nhất trong các chunk đã chọn — dùng cho câu trả lời dự phòng
            'top_passages' => $this->bestPassages($query, $topChunks, 3),
        ];
    }

    /**
     * PHẦN 4 TRONG PIPELINE RAG: TẦNG TRUY XUẤT LAI (HYBRID RETRIEVAL)
     * Kết hợp 3 bộ chấm điểm song song trên 35 chunks quy chế:
     *  - Bộ 1 (Lexical Matching): Khớp nguyên cụm câu hỏi (+40đ) và khớp từng từ khóa đơn >= 2 ký tự (+5đ/từ).
     *  - Bộ 2 (Intent Boosting): Cộng +20đ nếu topic_id của chunk khớp với nhóm ý định câu hỏi (classifyIntent).
     *  - Bộ 3 (Dense Vector Cosine Similarity): Nhúng câu hỏi thành vector qua AITeamVN/Vietnamese_Embedding_v2
     *    (hoặc Gemini fallback) rồi đo độ tương đồng Cosine [-1, 1] với vector lưu sẵn trong bảng MySQL `btec_kb_embeddings`.
     *  - Công thức hợp nhất Hybrid: CombinedScore = 100 * max(0, CosineSim) + 0.5 * LexicalScore.
     */
    private function retrieveChunks(string $rawQuery, string $cleanQuery, string $intent, array $terms): array
    {
        // Bước 2a: Điểm từ khóa (Lexical) theo cụm từ + độ hiếm (IDF) + tiêu đề mục, không phân biệt dấu.
        $this->buildLexicalIndex();
        $q = $this->queryFeatures($rawQuery);
        $faq = $this->matchFaq($q);
        $this->lastFaq = $faq;

        $byId = [];
        $lexical = [];
        $pureLexical = [];
        foreach ($this->chunks as $c) {
            $id = $c['chunk_id'] ?? null;
            if ($id === null || ! isset($this->lexIndex[$id])) {
                continue;
            }
            $byId[$id] = $c;

            $kwScore = $this->chunkLexicalScore($q, $id, (string) ($c['topic_id'] ?? ''));
            $pureLexical[$id] = $kwScore;

            $topicId = (string) ($c['topic_id'] ?? '');
            $boost = $kwScore > 0 ? $this->intentBoost($intent, $topicId) : 0;
            // Câu hỏi trùng một câu FAQ đã biên soạn -> ưu tiên đúng chủ đề FAQ đó.
            if ($faq !== null && $faq['topic_id'] === $topicId) {
                $boost += 30;
            }
            $lexical[$id] = $kwScore + $boost;
        }

        // Bước 2b: Chỉ gọi Vector Embedding mạng khi điểm từ khóa (Lexical) chưa đủ rõ (< 18đ) -> cắt ngay 1.5-4s cho 90% câu hỏi quy chế
        $maxPureLexical = ! empty($pureLexical) ? max($pureLexical) : 0;
        $vecSim = [];
        $useVector = false;
        $queryVector = ($maxPureLexical < self::LEXICAL_CONFIDENT && $faq === null && $this->embeddingService?->isConfigured())
            ? $this->embeddingService->embed($rawQuery, 'RETRIEVAL_QUERY')
            : null;

        if ($queryVector !== null) {
            try {
                // Lấy các vector đã được đánh chỉ mục trước bởi lệnh `php artisan btec:index-kb`
                $rows = BtecKbEmbedding::all(['chunk_id', 'embedding']);
                if ($rows->isNotEmpty()) {
                    foreach ($rows as $row) {
                        $vecSim[$row->chunk_id] = GeminiEmbeddingService::cosineSimilarity($queryVector, $row->vector());
                    }
                    $useVector = true;
                }
            } catch (\Throwable $e) {
                $useVector = false; // Bảng chưa migrate hoặc lỗi kết nối DB -> tự động fallback về thuần Lexical
            }
        }

        $this->lastRetrievalMethod = $useVector ? 'VECTOR_HYBRID' : 'LEXICAL';

        // Bước 2c: Hợp nhất điểm Hybrid (100 * CosineSim + 0.5 * LexicalScore) và lấy Top 4 chunks cao nhất
        $combined = [];
        foreach ($byId as $id => $chunk) {
            $kw = (float) ($lexical[$id] ?? 0);
            if ($useVector && isset($vecSim[$id])) {
                // Vector ngữ nghĩa chiếm trọng số chính (thang 0..100), từ khóa + intent bổ trợ chính xác thuật ngữ
                $combined[$id] = 100.0 * max(0.0, $vecSim[$id]) + 0.5 * $kw;
            } else {
                $combined[$id] = $kw;
            }
        }
        arsort($combined);

        $topId = array_key_first($combined);
        $this->lastTopScore = $topId !== null ? (float) $combined[$topId] : 0.0;
        if ($topId === null) {
            $this->lastIsConfident = false;
        } elseif ($useVector && isset($vecSim[$topId])) {
            $this->lastIsConfident = ($vecSim[$topId] >= 0.48) || (($pureLexical[$topId] ?? 0) >= self::LEXICAL_CONFIDENT) || $faq !== null;
        } else {
            $this->lastIsConfident = (($pureLexical[$topId] ?? 0) >= self::LEXICAL_CONFIDENT) || $faq !== null;
        }

        $topChunks = [];
        $bestScore = $this->lastTopScore;
        foreach (array_keys($combined) as $id) {
            if ($combined[$id] <= 0.0) {
                continue;
            }
            // Chỉ giữ đoạn thứ 2 nếu điểm >= 65% đoạn số 1 (tránh kéo theo các đoạn không liên quan)
            if (! empty($topChunks) && $combined[$id] < ($bestScore * 0.65)) {
                break;
            }
            $topChunks[] = $byId[$id];
            if (count($topChunks) >= 2) {
                break;
            }
        }

        return $topChunks;
    }

    // =========================================================================
    // CHẤM ĐIỂM TỪ KHÓA TIẾNG VIỆT
    // - Câu hỏi có dấu: so khớp CÓ dấu (tránh "bảo lưu" khớp nhầm "bao nhiêu").
    // - Câu hỏi gõ không dấu: so khớp trên bản đã bỏ dấu ("hoc phi" khớp "học phí").
    // - Chấm theo từ + cụm 2 từ, trọng số IDF (từ hiếm quan trọng hơn), khớp nhãn mục được nhân 1.6.
    // - Từ đồng nghĩa cách sinh viên hay nói ("nghỉ" ~ "vắng", "phúc khảo" ~ "phúc tra").
    // =========================================================================

    /** Từ dừng — không mang nghĩa phân biệt chủ đề. */
    private const STOP_WORDS = [
        'em', 'anh', 'chị', 'mình', 'tôi', 'bạn', 'có', 'không', 'ko', 'k', 'là', 'của', 'cho', 'với', 'và', 'hay',
        'hoặc', 'như', 'thế', 'nào', 'sao', 'được', 'chưa', 'rồi', 'ạ', 'nhé', 'ơi', 'vậy', 'này', 'đó', 'kia',
        'bị', 'ở', 'tại', 'khi', 'nếu', 'thì', 'về', 'các', 'những', 'một', 'gì', 'ai', 'muốn', 'cần', 'phải',
        'làm', 'nhiều', 'ít', 'rất', 'quá', 'cũng', 'đã', 'đang', 'sẽ', 'mà', 'nên', 'để', 'từ', 'đến', 'trong',
        'ra', 'vào', 'hiện', 'nhỉ', 'hả', 'bao', 'nhiêu', 'giờ', 'đâu', 'ạ', 'luôn', 'giúp', 'xin', 'cách',
    ];

    /** Từ dừng khi gõ không dấu (bỏ các từ dễ trùng nghĩa quan trọng như "co" = cơ sở). */
    private const STOP_WORDS_PLAIN = [
        'em', 'anh', 'chi', 'minh', 'toi', 'ban', 'khong', 'ko', 'k', 'la', 'cua', 'cho', 'voi', 'va', 'hay',
        'hoac', 'nhu', 'the', 'nao', 'sao', 'duoc', 'chua', 'roi', 'a', 'nhe', 'oi', 'vay', 'nay', 'kia',
        'bi', 'o', 'tai', 'khi', 'neu', 'thi', 've', 'cac', 'nhung', 'mot', 'gi', 'ai', 'muon', 'can', 'phai',
        'lam', 'nhieu', 'it', 'rat', 'qua', 'cung', 'da', 'dang', 'se', 'ma', 'nen', 'de', 'tu', 'den', 'trong',
        'ra', 'vao', 'hien', 'nhi', 'ha', 'nhieu', 'gio', 'dau', 'luon', 'giup', 'xin', 'cach',
    ];

    /** Cách nói của sinh viên -> thuật ngữ trong sổ tay (có dấu). */
    private const SYNONYMS = [
        'nghỉ học' => ['vắng', 'chuyên cần'], 'nghỉ' => ['vắng'], 'vắng' => ['nghỉ'],
        'cấm thi' => ['điều kiện thi', 'dự thi'], 'phần trăm' => ['20%', 'tỷ lệ'],
        'phúc khảo' => ['phúc tra'], 'phúc tra' => ['phúc khảo'],
        'trượt' => ['không đạt', 'học lại'], 'thi lại' => ['học lại', 'đánh giá lại'],
        'refer' => ['không đạt', 'resubmission', 'second chance'],
        'hoàn tiền' => ['hoàn phí', 'hoàn trả', 'hoàn số dư'], 'hoàn học phí' => ['hoàn phí', 'hoàn trả'],
        'đóng' => ['nộp', 'thanh toán'], 'trễ hạn' => ['quá hạn', 'muộn'], 'trễ' => ['muộn'],
        'ký túc xá' => ['nhà ở', 'ký túc'], 'ktx' => ['nhà ở', 'ký túc'],
        'gian lận' => ['trung thực', 'đạo văn'], 'chatgpt' => ['công cụ ai', 'sử dụng ai'],
        'mật khẩu' => ['đăng nhập', 'tài khoản'], 'việc làm' => ['tuyển dụng', 'doanh nghiệp'],
        'thời khóa biểu' => ['lịch học'], 'lịch học' => ['thời khóa biểu'],
        'giấy tờ' => ['hồ sơ'], 'đồng phục' => ['trang phục'], 'mất thẻ' => ['làm lại thẻ', 'cấp lại thẻ'],
        'số điện thoại' => ['tổng đài', 'hotline', 'danh bạ'], 'sđt' => ['tổng đài', 'hotline', 'danh bạ'],
        'liên hệ' => ['tổng đài', 'danh bạ', 'đầu mối'], 'email' => ['email hỗ trợ', 'danh bạ'],
        'quên mật khẩu' => ['lỗi tài khoản', 'báo lỗi'], 'không đăng nhập' => ['lỗi tài khoản', 'báo lỗi'],
        'không cho' => ['báo lỗi'], 'bị lỗi' => ['báo lỗi'],
        // Hỏi danh mục ngành / cơ sở của trường (mục 2.1), không phải thủ tục chuyển ngành (11.4)
        'bao nhiêu ngành' => ['ngành học', 'nhóm học'], 'bao nhiêu chuyên ngành' => ['ngành học', 'nhóm học'],
        'ngành nào' => ['ngành học', 'nhóm học'], 'những ngành' => ['ngành học', 'nhóm học'],
        'các ngành' => ['ngành học', 'nhóm học'], 'chuyên ngành nào' => ['ngành học', 'nhóm học'],
        'ngành gì' => ['ngành học', 'nhóm học'], 'đào tạo ngành' => ['ngành học', 'nhóm học'],
        'bao nhiêu cơ sở' => ['địa bàn', 'ngành học và cơ sở'], 'cơ sở nào' => ['địa bàn', 'ngành học và cơ sở'],
    ];

    /** Lỗi chính tả hay gặp trong câu hỏi (regex trên chữ thường). */
    private const TYPOS = [
        '/\bnghành\b/u' => 'ngành', '/\bnghanh\b/u' => 'nganh', '/\bngàng\b/u' => 'ngành',
    ];

    /** Chữ thường + bỏ dấu tiếng Việt. */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = [
            'a' => '/[àáạảãâầấậẩẫăằắặẳẵ]/u', 'e' => '/[èéẹẻẽêềếệểễ]/u', 'i' => '/[ìíịỉĩ]/u',
            'o' => '/[òóọỏõôồốộổỗơờớợởỡ]/u', 'u' => '/[ùúụủũưừứựửữ]/u', 'y' => '/[ỳýỵỷỹ]/u', 'd' => '/đ/u',
        ];
        foreach ($map as $plain => $re) {
            $s = preg_replace($re, $plain, $s) ?? $s;
        }

        return $s;
    }

    /** Câu không có ký tự tiếng Việt có dấu nào -> người dùng gõ không dấu. */
    private static function isPlain(string $s): bool
    {
        $lower = mb_strtolower($s, 'UTF-8');

        return self::fold($lower) === $lower;
    }

    /** Tách âm tiết: chế độ 'a' giữ dấu, chế độ 'f' bỏ dấu. Giữ "20%" thành "20". */
    private static function tokens(string $s, string $mode): array
    {
        $s = mb_strtolower($s, 'UTF-8');
        if ($mode === 'f') {
            $s = self::fold($s);
        }

        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $s) ?: [], fn ($t) => $t !== ''));
    }

    private static function bigrams(array $tokens): array
    {
        $out = [];
        for ($i = 0, $n = count($tokens) - 1; $i < $n; $i++) {
            $out[$tokens[$i] . ' ' . $tokens[$i + 1]] = true;
        }

        return $out;
    }

    /** Tiêu đề chunk + nhãn các mục "[4.1. Hồ sơ ...]" + dòng tiêu đề "4.1. Hồ sơ ...". */
    private static function headings(array $chunk): string
    {
        $text = (string) ($chunk['text'] ?? '');
        preg_match_all('/^\[([^\]\n]+)\]|^(\d+(?:\.\d+)+\.?\s[^\n]{3,120})$/mu', $text, $m);
        $labels = array_unique(array_filter(array_merge($m[1], $m[2])));

        return ($chunk['title'] ?? '') . "\n" . implode("\n", $labels);
    }

    /**
     * Tách một chunk thành các MỤC: nhãn "[4.1. Hồ sơ ...]" + nội dung các đoạn cùng nhãn.
     * Bỏ dòng chỉ là tiêu đề mục và dòng "Nguồn đối chiếu".
     */
    private static function sectionsOf(array $chunk): array
    {
        $sections = [];
        foreach (preg_split('/\R{2,}/u', (string) ($chunk['text'] ?? '')) ?: [] as $i => $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (preg_match('/^\[([^\]\n]+)\]\s*\R?(.*)$/su', $para, $m)) {
                $label = trim($m[1]);
                $body = trim($m[2]);
            } else {
                $label = '';
                $body = $para;
            }
            if ($body === '' || (! str_contains($body, "\n") && mb_strlen($body) < 90 && preg_match('/^\d+\.\d+(\.\d+)*\.?\s/u', $body))
                || preg_match('/^(Nguồn đối chiếu|Căn cứ)\s*:/u', $body)) {
                continue;
            }
            $key = $label !== '' ? $label : ('_' . $i);
            $sections[$key]['label'] = $label;
            $sections[$key]['parts'][] = $body;
        }

        return array_values(array_map(fn ($s) => ['label' => $s['label'], 'text' => implode("\n", $s['parts'])], $sections));
    }

    /** Đặc trưng của một đoạn ở cả 2 chế độ (có dấu 'a' / bỏ dấu 'f'). */
    private static function features(string $body, string $head): array
    {
        $out = [];
        foreach (['a', 'f'] as $mode) {
            $tokens = self::tokens($head . "\n" . $body, $mode);
            $headTokens = self::tokens($head, $mode);
            $out[$mode] = [
                'uni' => array_fill_keys($tokens, true),
                'bi' => self::bigrams($tokens),
                'head_uni' => array_fill_keys($headTokens, true),
                'head_bi' => self::bigrams($headTokens),
            ];
        }

        return $out;
    }

    /** Chỉ mục 2 tầng: toàn chunk + từng mục. IDF tính trên các mục (chi tiết hơn chunk). */
    private function buildLexicalIndex(): void
    {
        if (! empty($this->lexIndex)) {
            return;
        }

        $df = ['a' => [], 'f' => []];
        $nSections = 0;
        foreach ($this->chunks as $c) {
            $id = $c['chunk_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $sections = [];
            foreach (self::sectionsOf($c) as $sec) {
                // Nhãn mục là "tiêu đề" của mục; tiêu đề chunk chỉ tính ở tầng chunk (tránh mọi mục cùng điểm)
                $f = self::features($sec['text'], $sec['label']);
                $sections[] = $sec + ['f' => $f];
                $nSections++;
                foreach (['a', 'f'] as $mode) {
                    foreach (array_keys($f[$mode]['uni'] + $f[$mode]['bi']) as $term) {
                        $df[$mode][$term] = ($df[$mode][$term] ?? 0) + 1;
                    }
                }
            }
            $this->lexIndex[$id] = [
                'chunk' => self::features(($c['text'] ?? ''), self::headings($c)),
                'title' => self::features('', (string) ($c['title'] ?? '')),
                'sections' => $sections,
            ];
        }
        $this->df = $df;
        $this->nSections = max(1, $nSections);
    }

    private function idf(string $term, string $mode): float
    {
        $df = $this->df[$mode][$term] ?? 0;

        return $df === 0 ? 0.0 : log(1 + $this->nSections / $df);
    }

    /** Đặc trưng câu hỏi: từ có nghĩa + cụm 2 từ (ít nhất 1 từ có nghĩa) + từ đồng nghĩa. */
    private function queryFeatures(string $query): array
    {
        // Sửa lỗi chính tả phổ biến của sinh viên trước khi so khớp ("nghành" -> "ngành")
        $query = preg_replace(array_keys(self::TYPOS), array_values(self::TYPOS), mb_strtolower($query)) ?? $query;
        $mode = self::isPlain($query) ? 'f' : 'a';
        $stop = $mode === 'f' ? self::STOP_WORDS_PLAIN : self::STOP_WORDS;
        // Từ dừng, âm tiết 1 ký tự, số 1 chữ số ("1 kỳ") đều không mang nghĩa chủ đề; giữ số như "20" (20%)
        $isStop = fn (string $t) => in_array($t, $stop, true) || mb_strlen($t) < 2;

        $build = function (string $text) use ($mode, $isStop) {
            $tokens = self::tokens($text, $mode);
            $uni = [];
            foreach ($tokens as $t) {
                if (! $isStop($t)) {
                    $uni[$t] = true;
                }
            }
            $bi = [];
            foreach (array_keys(self::bigrams($tokens)) as $b) {
                [$x, $y] = explode(' ', $b);
                if (! $isStop($x) && ! $isStop($y)) {
                    $bi[$b] = true;
                }
            }

            return [array_keys($uni), array_keys($bi)];
        };

        [$uni, $bi] = $build($query);

        // Từ đồng nghĩa (so khớp trên câu hỏi; chế độ không dấu thì so khớp bản bỏ dấu)
        $synUni = [];
        $synBi = [];
        $haystack = ' ' . implode(' ', self::tokens($query, $mode)) . ' ';
        foreach (self::SYNONYMS as $key => $alts) {
            $keyNorm = implode(' ', self::tokens($key, $mode));
            if ($keyNorm === '' || ! str_contains($haystack, ' ' . $keyNorm . ' ')) {
                continue;
            }
            foreach ($alts as $alt) {
                [$u, $b] = $build($alt);
                $synUni = array_merge($synUni, $u);
                $synBi = array_merge($synBi, $b);
            }
        }

        return [
            'mode' => $mode,
            'uni' => $uni,
            'bi' => $bi,
            'syn_uni' => array_values(array_diff(array_unique($synUni), $uni)),
            'syn_bi' => array_values(array_diff(array_unique($synBi), $bi)),
        ];
    }

    /**
     * Điểm = Σ IDF(từ) + 2×Σ IDF(cụm 2 từ) (+ 0.7× cho từ đồng nghĩa); khớp trong nhãn mục được nhân 1.6.
     * Hệ số 3 đưa về thang điểm của ngưỡng tin cậy LEXICAL_CONFIDENT.
     */
    private function lexicalScore(array $q, array $entry): float
    {
        $mode = $q['mode'];
        $e = $entry[$mode];
        $score = 0.0;
        $sets = [
            ['uni', $q['uni'], 1.0], ['bi', $q['bi'], 2.0],
            ['uni', $q['syn_uni'], 0.7], ['bi', $q['syn_bi'], 1.4],
        ];
        foreach ($sets as [$kind, $terms, $weight]) {
            foreach ($terms as $t) {
                if (isset($e[$kind][$t])) {
                    $score += $weight * $this->idf($t, $mode) * (isset($e['head_' . $kind][$t]) ? 1.6 : 1.0);
                }
            }
        }

        return round($score * 3, 2);
    }

    /**
     * Điểm từ khóa của 1 chunk = mục khớp nhất + 50% tiêu đề chủ đề + 15% toàn chunk
     * (tránh chunk dài thắng chỉ vì nhiều chữ); chủ đề tham chiếu (T01, T22) bị giảm điểm.
     */
    private function chunkLexicalScore(array $q, string $id, string $topicId): float
    {
        $bestSection = 0.0;
        foreach ($this->lexIndex[$id]['sections'] as $sec) {
            $bestSection = max($bestSection, $this->lexicalScore($q, $sec['f']));
        }
        $score = $bestSection
            + 0.5 * $this->lexicalScore($q, $this->lexIndex[$id]['title'])
            + 0.15 * $this->lexicalScore($q, $this->lexIndex[$id]['chunk']);
        if (in_array($topicId, self::META_TOPICS, true)) {
            $score *= 0.4;
        }

        return round($score, 2);
    }

    /**
     * Kiểm tra NHANH (chỉ từ khóa, không gọi mạng) câu hỏi có khớp chắc chắn với sổ tay quy chế / FAQ không.
     * Dùng để chuyển thẳng câu hỏi quy chế sang RAG, bỏ qua LLM Intent Router chậm.
     */
    public function isConfidentHandbookMatch(string $query): bool
    {
        $this->buildLexicalIndex();
        $q = $this->queryFeatures($query);
        if ($this->matchFaq($q) !== null) {
            return true;
        }
        foreach ($this->chunks as $c) {
            $id = $c['chunk_id'] ?? null;
            if ($id !== null && isset($this->lexIndex[$id])
                && $this->chunkLexicalScore($q, $id, (string) ($c['topic_id'] ?? '')) >= self::LEXICAL_CONFIDENT) {
                return true;
            }
        }

        return false;
    }

    /** Khớp câu hỏi với bộ FAQ đã biên soạn (độ trùng từ/cụm từ >= 0.6). */
    private function matchFaq(array $q): ?array
    {
        if (count($q['uni']) < 2) {
            return null;
        }
        $qSet = array_fill_keys(array_merge($q['uni'], $q['bi']), true);

        $best = null;
        $bestSim = 0.0;
        foreach ($this->faqs as $f) {
            $question = (string) ($f['student_question'] ?? '');
            $fq = $this->queryFeatures($q['mode'] === 'f' ? self::fold($question) : $question);
            $fSet = array_fill_keys(array_merge($fq['uni'], $fq['bi']), true);
            if (empty($fSet)) {
                continue;
            }
            $inter = count(array_intersect_key($qSet, $fSet));
            $union = count($qSet + $fSet);
            $sim = $union > 0 ? $inter / $union : 0.0;
            if ($sim > $bestSim) {
                $bestSim = $sim;
                $best = $f;
            }
        }

        if ($best === null || $bestSim < 0.6) {
            return null;
        }

        return [
            'faq_id' => $best['faq_id'] ?? null,
            'topic_id' => (string) ($best['topic_id'] ?? ''),
            'question' => (string) ($best['student_question'] ?? ''),
            'guidance' => (string) ($best['answer_guidance'] ?? ''),
            'similarity' => round($bestSim, 2),
        ];
    }

    /**
     * Các MỤC sát câu hỏi nhất trong các chunk đã chọn — dùng cho câu trả lời dự phòng khi AI không phản hồi.
     * Mỗi phần tử: ['label' => '4.1. Hồ sơ ...', 'text' => '...', 'chunk_title' => ..., 'score' => float].
     */
    public function bestPassages(string $query, array $chunks, int $limit = 3): array
    {
        $this->buildLexicalIndex();
        $q = $this->queryFeatures($query);
        $passages = [];

        foreach ($chunks as $ci => $chunk) {
            $sections = $this->lexIndex[$chunk['chunk_id'] ?? '']['sections'] ?? [];
            foreach ($sections as $sec) {
                $passages[] = [
                    'label' => $sec['label'],
                    'text' => $sec['text'],
                    'chunk_title' => (string) ($chunk['title'] ?? ''),
                    // Ưu tiên nhẹ chunk xếp hạng cao hơn khi điểm mục bằng nhau
                    'score' => $this->lexicalScore($q, $sec['f']) - $ci * 0.5,
                ];
            }
        }

        usort($passages, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_values(array_filter($passages, fn ($p) => $p['score'] > 0)), 0, $limit);
    }

    /** Điểm cộng khi topic của chunk khớp với intent của câu hỏi. */
    private function intentBoost(string $intent, string $topicId): int
    {
        $map = [
            'REGULATION_QUESTION' => ['T07', 'T08', 'T13', 'T14'],
            'FINANCIAL_SUPPORT' => ['T05', 'T06'],
            'ACADEMIC_SUPPORT' => ['T09', 'T10', 'T11', 'T12'],
            'ADMISSION_SUPPORT' => ['T03', 'T04'],
            'STUDENT_SERVICE' => ['T02', 'T16', 'T17'],
        ];

        return isset($map[$intent]) && in_array($topicId, $map[$intent], true) ? 20 : 0;
    }
}

