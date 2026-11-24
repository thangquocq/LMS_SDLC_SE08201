<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Services\AI\DocumentTextExtractor;
use App\Services\AI\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// ( Đăng thêm ) Controller xử lý request sinh câu hỏi bằng AI
class AiController extends Controller
{
    private GeminiService $geminiService;
    private DocumentTextExtractor $extractor;

    public function __construct(GeminiService $geminiService, DocumentTextExtractor $extractor)
    {
        $this->geminiService = $geminiService;
        $this->extractor = $extractor;
    }

    public function generate(Request $request)
    {
        $request->validate([
            'topic' => 'required|string|max:255',
            'count' => 'required|integer|min:1|max:50',
            'document' => 'nullable|file|mimes:txt,pdf,docx|max:5120', // 5MB max
            'types' => 'nullable|array',
            'types.*' => 'in:mcq,true_false,fill_blank,code_reading',
            'difficulty' => 'nullable|string|max:50',
        ]);

        $topic = $request->input('topic');
        $count = $request->input('count');
        $types = $request->input('types', ['mcq']);
        $difficulty = $request->input('difficulty');
        $documentText = null;
        $inlineData = null;
        $documentInfo = null;

        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $extension = strtolower($file->getClientOriginalExtension());
            $path = $file->getRealPath();

            // Trích chữ ngay trong backend để CẢ Gemini lẫn Vilao dự phòng đều nhận được nội dung tài liệu.
            try {
                $documentText = $this->extractor->extract($path, $extension);
            } catch (\Throwable $e) {
                Log::error('AI Document Read Error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Không thể đọc file tài liệu: ' . $e->getMessage(),
                ], 422);
            }

            // Đã đính kèm tệp mà không đọc được chữ -> dừng, KHÔNG tự sinh quiz chỉ từ chủ đề.
            if (! $this->extractor->isUsable($documentText)) {
                $hint = $extension === 'pdf'
                    ? ' Có thể đây là PDF dạng ảnh scan (không có lớp chữ). Vui lòng dùng PDF có chữ chọn được, hoặc file Word/TXT.'
                    : ' Tệp gần như không có nội dung văn bản.';
                return response()->json([
                    'success' => false,
                    'message' => 'Không trích được nội dung từ tài liệu "' . $file->getClientOriginalName() . '".' . $hint,
                ], 422);
            }

            // PDF vẫn gửi kèm bản gốc cho Gemini (đọc được bảng/hình); Vilao dùng phần chữ đã trích.
            if ($extension === 'pdf') {
                $inlineData = [
                    'mimeType' => 'application/pdf',
                    'data' => base64_encode(file_get_contents($path)),
                ];
            }

            $totalChars = mb_strlen($documentText);
            $documentInfo = [
                'name' => $file->getClientOriginalName(),
                'chars' => $totalChars,
                'used_chars' => min($totalChars, GeminiService::MAX_DOCUMENT_CHARS),
                'truncated' => $totalChars > GeminiService::MAX_DOCUMENT_CHARS,
                'excerpt' => mb_substr($documentText, 0, 400),
            ];
        }

        try {
            $questions = $this->geminiService->generateQuestions($topic, $count, $documentText, $inlineData, $types, $difficulty);
            return response()->json([
                'success' => true,
                'data' => $questions,
                'document' => $documentInfo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi kết nối AI: ' . $e->getMessage()
            ], 500);
        }
    }
}
