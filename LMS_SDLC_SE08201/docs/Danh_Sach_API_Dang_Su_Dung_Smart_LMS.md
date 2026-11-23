# DANH SÁCH API ĐANG SỬ DỤNG — SMART LMS

Tài liệu tổng hợp toàn bộ **External AI APIs**, **Python ML/RAG Microservice APIs** và **Internal Laravel AJAX/SSE APIs** đang vận hành trong hệ thống **Smart LMS**.

---

## 1. External AI APIs (Dịch vụ AI bên ngoài)

| STT | Nhà cung cấp / Gateway | Endpoint | Phương thức | Model sử dụng | Biến cấu hình (`.env`) | Chức năng trong hệ thống |
| :-: | :--- | :--- | :-: | :--- | :--- | :--- |
| 1 | **Vilao AI Gateway** (OpenAI-compatible) | `https://api.vilao.ai/v1/chat/completions` | `POST` (SSE Stream & JSON) | `vgpt/gpt-6-sol` | `VILAO_BASE_URL`, `DEEPSEEK_KEY_MAIN`, `DEEPSEEK_MODEL_MAIN` | AI chính cho **Mr. Biết Tuốt**, **Trợ lý Học vụ BTEC**, **Zero-PII Intent Router**, **Kiểm duyệt ngữ nghĩa Tier-2** |
| 2 | **Vilao AI Gateway** (Content / Multimodal) | `https://api.vilao.ai/v1/chat/completions` | `POST` | `vgpt/gpt-6-sol` | `DEEPSEEK_KEY_CONTENT` | Xử lý câu hỏi kèm **ảnh / tệp PDF**, chế độ **Soi lỗi bài làm** & **Luyện tập câu hỏi** |
| 3 | **Vilao AI Gateway** (Fallback & Quiz) | `https://api.vilao.ai/v1/chat/completions` | `POST` | `vgpt/gpt-6.1-sol` | `OPENAI_API_KEY`, `OPENAI_MODEL`, `VILAO_API_KEY`, `VILAO_MODEL` | **AI Sinh câu hỏi Quiz** (Strict JSON) và **AI dự phòng** tự động khi AI chính lỗi/quá tải |
| 4 | **Vilao Image Generation API** | `https://api.vilao.ai/v1/images/generations` | `POST` | `rk/pln/flux` | `IMAGE_API_KEY`, `IMAGE_MODEL` | **Sinh ảnh minh họa học thuật bất đồng bộ (Async)** cho Mr. Biết Tuốt sau khi chữ đã hiển thị |
| 5 | **Google Gemini API** (LLM Fallback) | `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` | `POST` | `gemini-3.1-flash-lite` | `GEMINI_API_KEY`, `GEMINI_MODEL` | Dự phòng tầng cuối cho **Mr. Biết Tuốt** và **AI Sinh câu hỏi Quiz** |
| 6 | **Google Gemini Embedding API** | `https://generativelanguage.googleapis.com/v1beta/models/{model}:embedContent` | `POST` | `gemini-embedding-001` | `GEMINI_API_KEY`, `GEMINI_EMBED_MODEL` | Tạo vector embedding phục vụ **Hybrid RAG** (có Cache 24h & Circuit Breaker) |
| 7 | **HuggingFace Inference API** *(Tùy chọn)* | `https://api-inference.huggingface.co/pipeline/feature-extraction/{model}` | `POST` | `AITeamVN/Vietnamese_Embedding_v2` | `HF_API_TOKEN`, `VI_EMBED_MODEL` | Tầng embedding tiếng Việt dự phòng cho Hybrid RAG |

---

## 2. Local Python ML & Hybrid RAG Microservice APIs (`ml_service` — FastAPI `:8001`)

> Base URL mặc định: `http://127.0.0.1:8001` (cấu hình qua `ML_SERVICE_URL` và `ML_SERVICE_ENABLED` trong `.env`).

| STT | Endpoint | Phương thức | Mô hình / Thư viện | Chức năng |
| :-: | :--- | :-: | :--- | :--- |
| 1 | `/health` | `GET` | FastAPI | Kiểm tra trạng thái hoạt động của ML Service |
| 2 | `/predict` | `POST` | `scikit-learn` (Random Forest) + `SHAP` | Dự đoán xác suất nguy cơ bỏ học sinh viên kèm giải thích XAI |
| 3 | `/explain` | `POST` | `SHAP TreeExplainer` | Trả chi tiết các yếu tố ảnh hưởng đến mức rủi ro học tập |
| 4 | `/embed` | `POST` | `sentence-transformers` (`AITeamVN/Vietnamese_Embedding_v2`) | Nhúng văn bản tiếng Việt thành vector cho **Hybrid RAG** |
| 5 | `/summarize` | `POST` | `transformers` (`VietAI/vit5-base-vietnews-summarization`) + `PyMuPDF` / `python-docx` / `python-pptx` | Trích xuất & tóm tắt giáo trình PDF, Word, PowerPoint |
| 6 | `/compress-context` | `POST` | `Microsoft LLMLingua-2` | Nén ngữ cảnh hội thoại dài giảm 3×–6× token |

---

## 3. Internal Laravel AJAX & SSE APIs (`routes/web.php`)

### 3.1. AI Tutor — Mr. Biết Tuốt (`role:STUDENT`)
| Endpoint | Phương thức | Controller | Chức năng |
| :--- | :-: | :--- | :--- |
| `/student/ai-tutor/{conversation}/stream` | `POST` (SSE) | `Student\AiTutorController@stream` | Stream câu trả lời AI thời gian thực (4 chế độ: Giải thích, Hướng dẫn, Soi lỗi, Luyện tập) |
| `/student/ai-tutor/{conversation}/message` | `POST` (JSON) | `Student\AiTutorController@sendMessage` | Fallback AJAX khi kết nối SSE bị gián đoạn |
| `/student/ai-tutor/messages/{message}/generate-image` | `POST` (JSON) | `Student\AiTutorController@generateImage` | Sinh ảnh minh họa bất đồng bộ (`rk/pln/flux`) và cập nhật vào đúng vị trí trong tin nhắn |

### 3.2. Trợ lý Học vụ & Quy chế BTEC (`role:STUDENT,TEACHER,ADMIN`)
| Endpoint | Phương thức | Controller | Chức năng |
| :--- | :-: | :--- | :--- |
| `/student/btec-ai/init` | `GET` (JSON) | `Student\BtecSupportController@init` | Khởi tạo phiên chat & tải lịch sử hội thoại theo người dùng |
| `/student/btec-ai/stream` | `POST` (SSE) | `Student\BtecSupportController@stream` | Stream phản hồi Hybrid RAG + dữ liệu học vụ cá nhân có kiểm soát RBAC |
| `/student/btec-ai/chat` | `POST` (JSON) | `Student\BtecSupportController@chat` | Fallback AJAX cho Trợ lý Học vụ BTEC |
| `/student/btec-ai/ticket` | `POST` (JSON) | `Student\BtecSupportController@submitTicket` | Tạo Ticket hỗ trợ gửi Admin (khóa cứng ID & Tên người gửi theo phiên đăng nhập) |
| `/student/btec-ai/feedback` | `POST` (JSON) | `Student\BtecSupportController@feedback` | Đánh giá chất lượng câu trả lời AI (`helpful` / `not_helpful`) |
| `/student/btec-ai/reset` | `POST` (JSON) | `Student\BtecSupportController@reset` | Tạo cuộc trò chuyện hỗ trợ mới |

### 3.3. AI Sinh Quiz, Xếp lịch & Quản lý Ticket
| Endpoint | Phương thức | Controller | Chức năng |
| :--- | :-: | :--- | :--- |
| `/teacher/assignments/ai-generate` | `POST` (JSON) | `Teacher\AiController@generate` | Sinh bộ câu hỏi Quiz tự động (1–50 câu, 4 dạng, Strict JSON) từ chủ đề hoặc file tải lên |
| `/admin/classes/timetable` | `GET` (JSON) | `Admin\ClassController@timetable` | Kiểm tra lịch trống & phát hiện trùng phòng/trùng giảng viên thời gian thực |
| `/admin/tickets/{ticket}/forward` | `POST` | `Admin\TicketController@forward` | Admin chuyển tiếp Ticket cho Giảng viên phụ trách |
| `/admin/tickets/{ticket}/reply` | `POST` | `Admin\TicketController@reply` | Admin phản hồi trực tiếp & cập nhật trạng thái Ticket |
| `/teacher/tickets/{ticket}/reply` | `POST` | `Teacher\TicketController@reply` | Giảng viên báo cáo kết quả kiểm tra Ticket về cho Admin |
