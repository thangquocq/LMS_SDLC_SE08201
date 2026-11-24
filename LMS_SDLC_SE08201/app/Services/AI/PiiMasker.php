<?php

namespace App\Services\AI;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Che dữ liệu cá nhân (PII) trước khi gửi câu hỏi ra AI bên ngoài (Zero-PII).
 *
 * - AI bên ngoài chỉ nhận BẢN ĐÃ CHE (vd: "[MSSV]", "[EMAIL]").
 * - Backend vẫn giữ và dùng BẢN GỐC để tra cứu CSDL sau khi đã kiểm tra quyền (RBAC).
 * - Mã môn học (PRO192, DS19303...) KHÔNG bị che vì cần cho việc định tuyến môn.
 */
class PiiMasker
{
    private ?array $subjectCodes = null;

    public function mask(string $text, ?User $actor = null): string
    {
        if (trim($text) === '') {
            return $text;
        }

        // 1. Thông tin đã biết chắc của người đang hỏi (chính xác nhất).
        if ($actor) {
            foreach ([
                (string) ($actor->email ?? '') => '[EMAIL]',
                (string) ($actor->student_code ?? '') => '[MSSV]',
                (string) ($actor->phone ?? '') => '[SĐT]',
                (string) ($actor->full_name ?? '') => '[HỌ TÊN]',
            ] as $value => $label) {
                $value = trim($value);
                if (mb_strlen($value) >= 3) {
                    $text = preg_replace('/' . preg_quote($value, '/') . '/iu', $label, $text) ?? $text;
                }
            }
        }

        // 2. Email bất kỳ.
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[EMAIL]', $text) ?? $text;

        // 3. Số điện thoại VN (0xxx / +84xxx, cho phép dấu cách, chấm, gạch).
        $text = preg_replace('/(?<!\d)(?:\+?84|0)(?:[\s.\-]?\d){8,10}(?!\d)/u', '[SĐT]', $text) ?? $text;

        // 4. CCCD / CMND (12 hoặc 9 chữ số liền).
        $text = preg_replace('/(?<!\d)(?:\d{12}|\d{9})(?!\d)/u', '[CCCD]', $text) ?? $text;

        // 5. Mã sinh viên dạng chữ + số (BH02100, HL2600012...) — trừ mã môn học có trong hệ thống.
        $subjectCodes = $this->subjectCodes();
        $text = preg_replace_callback('/\b[A-Za-z]{2,3}\d{5,8}\b/u', function ($m) use ($subjectCodes) {
            return in_array(strtoupper($m[0]), $subjectCodes, true) ? $m[0] : '[MSSV]';
        }, $text) ?? $text;

        // 6. Họ tên tự giới thiệu: "tên em là Nguyễn Văn A", "mình là Trần B".
        $text = preg_replace(
            '/\b((?:tên|ten)\s+(?:em|tôi|toi|mình|minh|tớ|to|con)\s+là|(?:em|tôi|mình|tớ)\s+tên\s+là|(?:em|tôi|mình|tớ)\s+tên)\s+(\p{Lu}[\p{L}]*(?:\s+\p{Lu}[\p{L}]*){0,4})/u',
            '$1 [HỌ TÊN]',
            $text
        ) ?? $text;

        return $text;
    }

    /** Danh sách mã môn học (viết hoa) để không che nhầm. */
    private function subjectCodes(): array
    {
        if ($this->subjectCodes !== null) {
            return $this->subjectCodes;
        }

        try {
            $this->subjectCodes = cache()->remember('pii_masker_subject_codes', 3600, fn () => Subject::query()
                ->pluck('subject_code')
                ->filter()
                ->map(fn ($c) => strtoupper(trim((string) $c)))
                ->values()
                ->all());
        } catch (\Throwable $e) {
            Log::info('PiiMasker: khong doc duoc ma mon hoc: ' . $e->getMessage());
            $this->subjectCodes = [];
        }

        return $this->subjectCodes;
    }
}
