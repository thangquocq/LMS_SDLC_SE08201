<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lưu nội dung kho tri thức RAG (các đoạn quy chế mà Admin quản lý) trong MySQL.
 * Trước đây nội dung nằm ở file storage/btec_ai_kb/kb_chunks.jsonl bên trong container,
 * nên trên Render mọi chỉnh sửa của Admin bị mất mỗi lần deploy / server ngủ dậy.
 * File đó giờ chỉ dùng để nạp dữ liệu ban đầu khi bảng còn trống.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('btec_kb_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('chunk_id')->unique();
            $table->string('topic_id', 32)->nullable()->index();
            $table->string('title')->nullable();
            $table->unsignedInteger('position')->default(0);   // giữ thứ tự hiển thị như file gốc
            $table->longText('payload');                        // toàn bộ đoạn tri thức dạng JSON (text, source_refs, ...)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('btec_kb_chunks');
    }
};
