<?php

namespace Database\Seeders;

use App\Models\PolicyRule;
use Illuminate\Database\Seeder;

/**
 * Nạp quy định chuyên cần BTEC cho trợ lý AI (chạy lại nhiều lần không bị trùng).
 */
class BtecPolicyRuleSeeder extends Seeder
{
    public function run(): void
    {
        PolicyRule::updateOrCreate(
            ['rule_code' => PolicyRule::CODE_ATTENDANCE_MAX_ABSENCE, 'scope_type' => PolicyRule::SCOPE_GLOBAL],
            [
                'title' => 'Quy chế chuyên cần chuẩn: Vắng quá 20% tổng số buổi bị trượt môn',
                'source_document' => 'Sổ tay sinh viên BTEC FPT ver.BTEC.9.2024',
                'clause_ref' => 'Mục Điểm danh & Chuyên cần',
                'excerpt' => 'Sinh viên vắng quá 20% tổng số buổi học của môn sẽ không đủ điều kiện dự thi / trượt môn.',
                'scope_id' => null,
                'term_code' => null,
                'effective_from' => '2025-11-23',
                'effective_to' => null,
                'status' => PolicyRule::STATUS_PUBLISHED,
                'priority' => 100,
                'rule_type' => PolicyRule::TYPE_ATTENDANCE_THRESHOLD,
                'threshold_value' => 20,
                'operator' => PolicyRule::OP_GT,
                'unit' => 'PERCENT',
                'basis' => 'TOTAL_CLASS_SESSIONS',
                'requires_engineering_support' => false,
            ]
        );
    }
}
