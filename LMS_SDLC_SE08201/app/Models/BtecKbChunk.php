<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Một đoạn tri thức (KB chunk) của kho RAG BTEC, lưu trong MySQL.
 * Cột `payload` chứa toàn bộ đoạn dạng JSON (chunk_id, topic_id, title, text, source_refs, ...).
 */
class BtecKbChunk extends Model
{
    protected $table = 'btec_kb_chunks';

    protected $fillable = ['chunk_id', 'topic_id', 'title', 'position', 'payload'];

    /** Giải mã payload thành mảng đoạn tri thức. */
    public function toChunk(): array
    {
        $decoded = json_decode((string) $this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
