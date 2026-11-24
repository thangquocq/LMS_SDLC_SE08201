<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// ( Đăng thêm ) Service kết nối Google Gemini AI (có dự phòng Vilao AI) để sinh câu hỏi trắc nghiệm
class GeminiService
{
    /** Số ký tự tài liệu tối đa đưa vào prompt (dùng chung cho Gemini và Vilao). */
    public const MAX_DOCUMENT_CHARS = 30000;

    private string $apiKey;
    private string $geminiModel;
    private string $vilaoApiKey;
    private string $vilaoBaseUrl;
    private string $vilaoModel;

    public function __construct()
    {
        $this->apiKey = (string) env('GEMINI_API_KEY', '');
        $this->geminiModel = (string) env('GEMINI_MODEL', 'gemini-2.0-flash');
        $this->vilaoApiKey = (string) env('VILAO_API_KEY', '');
        $this->vilaoBaseUrl = rtrim((string) env('VILAO_BASE_URL', 'https://api.vilao.ai/v1'), '/');
        $this->vilaoModel = (string) env('VILAO_MODEL', 'vgpt/gpt-6.1-sol');
    }

    /**
     * Generate questions based on topic, count, and optional document text or file.
     */
    public function generateQuestions(
        string $topic,
        int $count,
        ?string $documentText = null,
        ?array $inlineData = null,
        array $types = ['mcq'],
        ?string $difficulty = null
    ): array {
        if (empty($this->apiKey) && empty($this->vilaoProviders())) {
            throw new \Exception('Chưa cấu hình API Key AI (GEMINI_API_KEY hoặc VILAO_API_KEY) trong .env');
        }

        // Gemini quá tải + chuyển sang Vilao có thể mất hơn 120 giây (giới hạn mặc định của XAMPP)
        @set_time_limit(240);

        // Chuẩn hóa danh sách dạng bài được yêu cầu (mặc định: trắc nghiệm nhiều lựa chọn)
        $allowed = ['mcq', 'true_false', 'fill_blank']; // AI không sinh dạng Đọc code
        $types = array_values(array_intersect(array_map('strtolower', $types), $allowed));
        if (empty($types)) {
            $types = ['mcq'];
        }
        $typesList = implode(', ', $types);
        $difficultyLine = $this->buildDifficultyLine($difficulty, $count);

        $systemInstruction = "Bạn là một giáo sư đại học chuyên ngành khắt khe. Nhiệm vụ của bạn là tạo ra $count câu hỏi ôn tập dựa trên chủ đề được giao, phân bổ xen kẽ trong CÁC DẠNG BÀI sau: {$typesList}.
BẮT BUỘC TUÂN THỦ CÁC QUY TẮC SAU:
1. " . ($documentText ? "CHỈ ĐƯỢC PHÉP sử dụng kiến thức có TRONG TÀI LIỆU CUNG CẤP BÊN DƯỚI. Tuyệt đối không được bịa đặt (hallucinate) hay sử dụng kiến thức bên ngoài nếu tài liệu không đề cập." : "Đảm bảo kiến thức chính xác tuyệt đối theo chuẩn học thuật.") . "
2. Format output trả về BẮT BUỘC phải là một mảng JSON nguyên chất, KHÔNG bọc trong markdown block (như ```json ... ```). Hãy bắt đầu bằng dấu [ và kết thúc bằng dấu ].
3. Mỗi object là MỘT câu hỏi, BẮT BUỘC có trường \"question_type\" là một trong: mcq, true_false, fill_blank, code_reading, và trường \"explanation\" (giải thích ngắn gọn vì sao đáp án đúng). Tùy dạng bài, thêm các trường:
   - mcq: \"content\", \"option_a\", \"option_b\", \"option_c\", \"option_d\", \"correct_answer\" (một chữ in hoa A/B/C/D).
   - true_false: \"content\" (một mệnh đề), \"option_a\" = \"Đúng\", \"option_b\" = \"Sai\", \"correct_answer\" = \"A\" hoặc \"B\".
   - fill_blank: \"content\" (câu có chỗ trống thể hiện bằng dấu ___), \"correct_text\" (đáp án đúng; nếu chấp nhận nhiều cách viết thì ngăn cách bằng dấu |). KHÔNG cần option.
   - code_reading: \"content\" chứa một đoạn code viết TRỰC TIẾP trên các dòng riêng (KHÔNG dùng dấu ``` hay thẻ HTML), 4 lựa chọn \"option_a\"..\"option_d\" là các kết quả/giải thích có thể, \"correct_answer\" là chữ in hoa A/B/C/D.
4. Chỉ sinh các dạng bài nằm trong danh sách yêu cầu: {$typesList}.
5. TUYỆT ĐỐI KHÔNG dùng dấu ``` (ba dấu huyền) ở bất kỳ đâu. Chỉ trả về MẢNG JSON THUẦN, ký tự đầu tiên là [ và ký tự cuối là ].";

        $userPrompt = "Chủ đề: $topic\nSố lượng câu hỏi: $count\nCác dạng bài yêu cầu: {$typesList}.\n{$difficultyLine}\n";
        if ($documentText) {
            // Cùng một nội dung tài liệu cho cả Gemini lẫn Vilao dự phòng.
            $docPart = mb_substr($documentText, 0, self::MAX_DOCUMENT_CHARS);
            if (mb_strlen($documentText) > self::MAX_DOCUMENT_CHARS) {
                // Cắt ở ranh giới đoạn gần nhất để không làm gãy câu cuối.
                $cut = mb_strrpos($docPart, "\n");
                if ($cut !== false && $cut > self::MAX_DOCUMENT_CHARS * 0.8) {
                    $docPart = mb_substr($docPart, 0, $cut);
                }
            }
            $userPrompt .= "TÀI LIỆU THAM KHẢO (chỉ được dùng nội dung này):\n```\n" . $docPart . "\n```\n";
        }
        $userPrompt .= "Hãy sinh ra danh sách $count câu hỏi dưới dạng mảng JSON theo đúng cấu trúc trên.";

        $lastError = null;

        // 1. Thử gọi Google Gemini API nếu có key hợp lệ
        if (! empty($this->apiKey)) {
            try {
                $parts = [];
                if ($inlineData) {
                    $parts[] = ['inlineData' => $inlineData];
                }
                $parts[] = ['text' => $userPrompt];

                $payload = [
                    'system_instruction' => [
                        'parts' => [
                            ['text' => $systemInstruction],
                        ],
                    ],
                    'contents' => [
                        [
                            'parts' => $parts,
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 8192,
                    ],
                ];

                // Google hay quá tải tạm thời (503/429): thử lại model chính 1 lần,
                // rồi lần lượt chuyển sang các model dự phòng trong GEMINI_FALLBACK_MODELS.
                $fallbackModels = array_filter(array_map('trim', explode(',', (string) env(
                    'GEMINI_FALLBACK_MODELS',
                    'gemini-3.5-flash-lite,gemini-3.1-flash-lite,gemini-3.8-flash'
                ))));
                $models = array_values(array_unique(array_merge([$this->geminiModel], $fallbackModels)));

                foreach ($models as $index => $model) {
                    $attempts = $index === 0 ? 2 : 1;
                    for ($try = 1; $try <= $attempts; $try++) {
                        $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
                        try {
                            $response = Http::timeout(30)->withHeaders([
                                'Content-Type' => 'application/json',
                                'x-goog-api-key' => $this->apiKey,
                            ])->post($geminiUrl, $payload);
                        } catch (\Illuminate\Http\Client\ConnectionException $e) {
                            // Hết thời gian chờ / mất kết nối -> chuyển sang model kế tiếp
                            $lastError = "Gemini ({$model}) timeout";
                            Log::warning("Gemini ({$model}) timeout: " . $e->getMessage());
                            break;
                        }

                        if ($response->successful()) {
                            // Model Gemini 3.x có thể chia câu trả lời thành nhiều "parts"
                            // (kèm phần suy nghĩ thought=true) -> ghép mọi phần văn bản, bỏ phần suy nghĩ.
                            $textResult = '';
                            foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
                                if (! empty($part['thought'])) {
                                    continue;
                                }
                                $textResult .= (string) ($part['text'] ?? '');
                            }
                            $finishReason = (string) $response->json('candidates.0.finishReason', '');
                            if ($finishReason !== '' && $finishReason !== 'STOP') {
                                Log::warning("Gemini ({$model}) dừng với finishReason={$finishReason}.");
                            }
                            if ($textResult !== '') {
                                return $this->parseAndNormalizeQuestions($textResult);
                            }
                            $lastError = "Gemini ({$model}) trả về nội dung rỗng";
                            break;
                        }

                        $status = $response->status();
                        $lastError = "Gemini ({$model}) HTTP {$status}";
                        Log::warning("Gemini API non-200 ({$status}) với model {$model}, lần thử {$try}.");

                        // Chỉ thử lại khi lỗi tạm thời; lỗi khác (404 model, 400...) chuyển model luôn
                        if (! in_array($status, [429, 500, 503], true)) {
                            break;
                        }
                        if ($try < $attempts) {
                            usleep(1500000);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Gemini Service warning, falling back to Vilao AI: ' . $e->getMessage());
                $lastError = $e->getMessage();
            }
        }

        // 2. Dự phòng tự động sang Vilao AI (OpenAI-compatible API), thử lần lượt từng key/model đã cấu hình
        foreach ($this->vilaoProviders() as $provider) {
            try {
                $response = Http::timeout(90)->withHeaders([
                    'Authorization' => 'Bearer ' . $provider['key'],
                    'Content-Type' => 'application/json',
                ])->post($this->vilaoBaseUrl . '/chat/completions', [
                    'model' => $provider['model'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemInstruction],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 6000,
                ]);

                if (! $response->successful()) {
                    Log::error('Vilao Quiz AI Error (model=' . $provider['model'] . '): ' . mb_substr($response->body(), 0, 300));
                    throw new \Exception('Không thể kết nối dịch vụ AI (' . $response->status() . ')');
                }

                $textResult = (string) ($response->json('choices.0.message.content') ?? '');

                return $this->parseAndNormalizeQuestions($textResult);
            } catch (\Throwable $e) {
                Log::error('Vilao Quiz AI Exception: ' . $e->getMessage());
                $lastError = $e->getMessage();
            }
        }

        throw new \Exception($lastError ?: 'Không thể kết nối đến dịch vụ AI');
    }

    /**
     * Danh sách key/model Vilao theo thứ tự: VILAO_API_KEY → DEEPSEEK_KEY_MAIN → OPENAI_API_KEY (bỏ key trùng).
     * Một key bị từ chối (403: chưa đăng ký model) thì tự chuyển sang key kế tiếp.
     */
    private function vilaoProviders(): array
    {
        $candidates = [
            [$this->vilaoApiKey, $this->vilaoModel],
            [env('DEEPSEEK_KEY_MAIN'), env('DEEPSEEK_MODEL_MAIN') ?: env('DEEPSEEK_MODEL') ?: 'vgpt/gpt-6-sol'],
            [env('OPENAI_API_KEY'), env('OPENAI_MODEL') ?: 'vgpt/gpt-6.1-sol'],
        ];

        $providers = [];
        $seen = [];
        foreach ($candidates as [$key, $model]) {
            $key = trim((string) $key);
            if ($key === '' || in_array($key, $seen, true)) {
                continue;
            }
            $seen[] = $key;
            $providers[] = ['key' => $key, 'model' => (string) $model];
        }

        return $providers;
    }

    /**
     * Làm sạch chuỗi JSON trả về từ AI và chuẩn hóa các trường câu hỏi trắc nghiệm.
     */
    /**
     * Chuyển mức độ khó giảng viên chọn (Nhận biết / Thông hiểu / Vận dụng / Trộn)
     * thành chỉ dẫn rõ ràng cho AI.
     */
    private function buildDifficultyLine(?string $difficulty, int $count): string
    {
        $levels = [
            'nhan_biet' => 'NHẬN BIẾT: nhớ và nhận ra khái niệm, định nghĩa, cú pháp, sự kiện cơ bản.',
            'thong_hieu' => 'THÔNG HIỂU: giải thích, so sánh, diễn giải ý nghĩa; hiểu vì sao và như thế nào.',
            'van_dung' => 'VẬN DỤNG: áp dụng kiến thức vào tình huống, bài toán hoặc đoạn code cụ thể.',
        ];

        $key = strtolower(trim((string) $difficulty));

        if (isset($levels[$key])) {
            return "Độ khó: TẤT CẢ câu hỏi ở mức {$levels[$key]}\n";
        }

        // Mặc định / "mix": chia đều 3 mức
        $base = intdiv($count, 3);
        $rest = $count % 3;
        $nb = $base + ($rest > 0 ? 1 : 0);
        $th = $base + ($rest > 1 ? 1 : 0);
        $vd = $base;

        return "Độ khó: TRỘN đều 3 mức — khoảng {$nb} câu {$levels['nhan_biet']} "
            . "Khoảng {$th} câu {$levels['thong_hieu']} Khoảng {$vd} câu {$levels['van_dung']}\n";
    }

    private function parseAndNormalizeQuestions(string $rawText): array
    {
        $cleaned = preg_replace('/<thought>[\s\S]*?<\/thought>/i', '', $rawText);
        $cleaned = trim((string) $cleaned);

        // Chỉ gỡ cặp dấu ``` BAO NGOÀI toàn bộ phản hồi (không đụng code fence bên trong nội dung câu hỏi)
        $cleaned = preg_replace('/^```[a-zA-Z]*\s*/', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $startPos = strpos($cleaned, '[');
        $endPos = strrpos($cleaned, ']');
        if ($startPos !== false && $endPos !== false && $endPos > $startPos) {
            $cleaned = substr($cleaned, $startPos, $endPos - $startPos + 1);
        }

        $decoded = json_decode($cleaned, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::error('AI Quiz JSON Parse Error. Raw output: ' . $rawText);
            throw new \Exception('Định dạng dữ liệu câu hỏi trả về từ AI không hợp lệ.');
        }

        $normalized = [];
        foreach ($decoded as $item) {
            if (! is_array($item) || empty($item['content'])) {
                continue;
            }

            $type = strtolower(trim((string) ($item['question_type'] ?? 'mcq')));
            if (! in_array($type, ['mcq', 'true_false', 'fill_blank', 'code_reading'], true)) {
                $type = 'mcq';
            }

            $explanation = trim((string) ($item['explanation'] ?? ''));

            if ($type === 'fill_blank') {
                $correctText = trim((string) ($item['correct_text'] ?? ''));
                if ($correctText === '') {
                    continue; // thiếu đáp án -> bỏ qua câu này
                }
                $normalized[] = [
                    'question_type' => 'fill_blank',
                    'content' => trim((string) $item['content']),
                    'option_a' => null,
                    'option_b' => null,
                    'option_c' => null,
                    'option_d' => null,
                    'correct_answer' => null,
                    'correct_text' => $correctText,
                    'explanation' => $explanation,
                ];
                continue;
            }

            // true_false: ép còn 2 lựa chọn Đúng/Sai
            if ($type === 'true_false') {
                $optA = trim((string) ($item['option_a'] ?? 'Đúng')) ?: 'Đúng';
                $optB = trim((string) ($item['option_b'] ?? 'Sai')) ?: 'Sai';
                $ans = strtoupper(substr(trim((string) ($item['correct_answer'] ?? 'A')), 0, 1));
                if (! in_array($ans, ['A', 'B'], true)) {
                    $ans = 'A';
                }
                $normalized[] = [
                    'question_type' => 'true_false',
                    'content' => trim((string) $item['content']),
                    'option_a' => $optA,
                    'option_b' => $optB,
                    'option_c' => null,
                    'option_d' => null,
                    'correct_answer' => $ans,
                    'correct_text' => null,
                    'explanation' => $explanation,
                ];
                continue;
            }

            // mcq và code_reading: 4 lựa chọn A/B/C/D
            $ans = strtoupper(substr(trim((string) ($item['correct_answer'] ?? 'A')), 0, 1));
            if (! in_array($ans, ['A', 'B', 'C', 'D'], true)) {
                $ans = 'A';
            }
            $normalized[] = [
                'question_type' => $type,
                'content' => trim((string) $item['content']),
                'option_a' => trim((string) ($item['option_a'] ?? '')),
                'option_b' => trim((string) ($item['option_b'] ?? '')),
                'option_c' => trim((string) ($item['option_c'] ?? '')),
                'option_d' => trim((string) ($item['option_d'] ?? '')),
                'correct_answer' => $ans,
                'correct_text' => null,
                'explanation' => $explanation,
            ];
        }

        if (empty($normalized)) {
            throw new \Exception('AI không trả về câu hỏi hợp lệ nào, vui lòng thử lại.');
        }

        return $normalized;
    }
}
