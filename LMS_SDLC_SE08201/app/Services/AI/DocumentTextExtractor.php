<?php

namespace App\Services\AI;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Trích chữ từ tệp TXT / DOCX / PDF ngay trong backend để gửi cho MỌI model AI
 * (Gemini lẫn Vilao dự phòng) — không phụ thuộc model có đọc được tệp nhị phân hay không.
 *
 * PDF dạng ảnh scan không có lớp chữ -> trả về chuỗi rỗng; nơi gọi phải báo lỗi cho người dùng
 * thay vì để AI tự bịa nội dung.
 */
class DocumentTextExtractor
{
    /** Số ký tự tối thiểu để coi là "đọc được tài liệu". */
    public const MIN_CHARS = 50;

    public const SUPPORTED = ['txt', 'md', 'csv', 'docx', 'pdf'];

    /** Các đuôi tệp mã nguồn / văn bản thuần, đọc trực tiếp. */
    private const PLAIN_TEXT = [
        'txt', 'md', 'csv', 'json', 'xml', 'html', 'css', 'js', 'ts', 'php', 'py', 'java',
        'c', 'cpp', 'h', 'cs', 'sql', 'kt', 'swift', 'go', 'rb', 'yml', 'yaml', 'ini', 'log',
    ];

    public function isPlainText(string $extension): bool
    {
        return in_array(strtolower($extension), self::PLAIN_TEXT, true);
    }

    public function canExtract(string $extension): bool
    {
        $ext = strtolower($extension);

        return $ext === 'docx' || $ext === 'pdf' || $this->isPlainText($ext);
    }

    /**
     * Trích chữ theo đuôi tệp. Trả về chuỗi đã chuẩn hoá khoảng trắng (có thể rỗng).
     *
     * @throws \RuntimeException khi tệp hỏng / không mở được.
     */
    public function extract(string $path, string $extension): string
    {
        $ext = strtolower($extension);

        $text = match (true) {
            $ext === 'docx' => $this->fromDocx($path),
            $ext === 'pdf' => $this->fromPdf($path),
            $this->isPlainText($ext) => (string) file_get_contents($path),
            default => throw new \RuntimeException("Không hỗ trợ đọc tệp .{$ext}"),
        };

        return $this->normalize($text);
    }

    /** Đủ nội dung để AI bám vào hay chưa. */
    public function isUsable(?string $text): bool
    {
        return $text !== null && mb_strlen(trim($text)) >= self::MIN_CHARS;
    }

    private function fromDocx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Không thể giải nén tệp DOCX (tệp hỏng hoặc không phải Word).');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new \RuntimeException('Tệp DOCX không có nội dung văn bản (word/document.xml).');
        }

        // Giữ ranh giới đoạn / dòng / tab trước khi bỏ thẻ XML. Không bật LIBXML_NOENT (tránh lỗ hổng XXE).
        $xml = preg_replace('#</w:p>#', "\n", $xml);
        $xml = preg_replace('#<w:(br|cr)[^>]*/>#', "\n", $xml);
        $xml = preg_replace('#<w:tab[^>]*/>#', "\t", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function fromPdf(string $path): string
    {
        try {
            $pdf = (new PdfParser())->parseFile($path);

            return (string) $pdf->getText();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Không đọc được tệp PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    private function normalize(string $text): string
    {
        // Loại ký tự điều khiển / byte lỗi UTF-8 để JSON gửi AI không bị hỏng.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n[ \t]*\n(\s*\n)+/", "\n\n", $text);

        return trim((string) $text);
    }
}
