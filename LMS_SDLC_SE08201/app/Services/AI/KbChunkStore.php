<?php

namespace App\Services\AI;

use App\Models\BtecKbChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Nơi lưu trữ DUY NHẤT của kho tri thức RAG (dùng chung cho trang Admin và Trợ lý Học vụ).
 *
 * - Dữ liệu chính nằm trong MySQL (bảng btec_kb_chunks) nên không bị mất khi Render deploy/khởi động lại.
 * - Khi bảng còn trống, tự nạp dữ liệu ban đầu từ file storage/btec_ai_kb/kb_chunks.jsonl.
 * - Nếu chưa chạy migration hoặc mất kết nối DB, đọc tạm từ file để trợ lý vẫn hoạt động.
 */
class KbChunkStore
{
    public function seedFilePath(): string
    {
        return storage_path('btec_ai_kb/kb_chunks.jsonl');
    }

    /** Toàn bộ đoạn tri thức theo đúng thứ tự. */
    public function all(): array
    {
        if (! $this->tableReady()) {
            return $this->readSeedFile();
        }

        try {
            if (! BtecKbChunk::query()->exists()) {
                $this->seedFromFile();
            }

            return BtecKbChunk::query()
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (BtecKbChunk $row) => $row->toChunk())
                ->filter(fn (array $c) => ! empty($c['chunk_id']))
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::error('KbChunkStore: doc DB that bai, dung file goc. ' . $e->getMessage());

            return $this->readSeedFile();
        }
    }

    /**
     * Ghi lại toàn bộ kho (thêm / sửa / xoá) trong một transaction.
     * Đoạn nào không còn trong danh sách sẽ bị xoá khỏi DB.
     */
    public function replaceAll(array $chunks): void
    {
        $chunks = array_values(array_filter($chunks, fn ($c) => is_array($c) && ! empty($c['chunk_id'])));

        DB::transaction(function () use ($chunks) {
            $keepIds = [];
            foreach ($chunks as $i => $chunk) {
                $keepIds[] = (string) $chunk['chunk_id'];
                BtecKbChunk::updateOrCreate(
                    ['chunk_id' => (string) $chunk['chunk_id']],
                    [
                        'topic_id' => $chunk['topic_id'] ?? null,
                        'title' => isset($chunk['title']) ? mb_substr((string) $chunk['title'], 0, 255) : null,
                        'position' => $i,
                        'payload' => json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]
                );
            }

            BtecKbChunk::query()->whereNotIn('chunk_id', $keepIds)->delete();
        });
    }

    /** Nạp dữ liệu ban đầu từ file JSONL (chỉ gọi khi bảng trống). */
    public function seedFromFile(): int
    {
        $chunks = $this->readSeedFile();
        if (empty($chunks)) {
            return 0;
        }

        $this->replaceAll($chunks);
        Log::info('KbChunkStore: da nap ' . count($chunks) . ' doan tri thuc tu file goc vao MySQL.');

        return count($chunks);
    }

    public function readSeedFile(): array
    {
        $path = $this->seedFilePath();
        if (! file_exists($path)) {
            return [];
        }

        $chunks = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ! empty($decoded['chunk_id'])) {
                $chunks[] = $decoded;
            }
        }

        return $chunks;
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('btec_kb_chunks');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
