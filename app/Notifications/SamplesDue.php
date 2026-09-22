<?php

namespace App\Notifications;

use App\Models\Sample;
use App\Services\SampleTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * NHẮC HẠN MẪU VẬT LÝ (Việc #4 — 2026-09-26).
 *
 * Vì sao cần: mẫu fit/PP/TOP có hạn chót, mà hạn trôi qua trong im lặng thì trễ cả bộ sưu tập. Giao diện
 * đã hiện cảnh báo (tính từ dữ liệu, luôn đúng); thông báo này để người dùng KHÔNG PHẢI MỞ ỨNG DỤNG mới
 * biết — nhưng vẫn nói rõ là do LỊCH NHẮC, không phải "AI phát hiện".
 */
class SamplesDue extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, Sample>  $samples
     */
    public function __construct(
        public Collection $samples,
        public int $overdue = 0,
        public int $dueSoon = 0,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Có '.$this->samples->count().' mẫu tới hạn hoặc quá hạn — FabrikAI')
            ->greeting('Xin chào!')
            ->line($this->overdue > 0
                ? $this->overdue.' mẫu ĐÃ QUÁ HẠN và '.$this->dueSoon.' mẫu sắp tới hạn.'
                : $this->dueSoon.' mẫu sắp tới hạn trong '.SampleTrackingService::DUE_SOON_DAYS.' ngày tới.');

        // Trần 15 dòng: hộp thư không phải bảng theo dõi — danh sách đầy đủ nằm trong ứng dụng.
        foreach ($this->samples->take(15) as $sample) {
            $days = SampleTrackingService::daysLeft($sample);
            $when = $days === null
                ? 'chưa khai hạn'
                : ($days < 0 ? 'quá '.abs($days).' ngày' : 'còn '.$days.' ngày');

            $mail->line(sprintf(
                '• %s · %s — %s (%s)',
                $sample->style_no ?: ('Mẫu #'.$sample->id),
                $sample->name ?: 'chưa đặt tên',
                Sample::STAGE_LABELS[$sample->stage] ?? $sample->stage,
                $when,
            ));
        }

        if ($this->samples->count() > 15) {
            $mail->line('… và '.($this->samples->count() - 15).' mẫu nữa — xem đầy đủ trong ứng dụng.');
        }

        return $mail
            ->action('Mở bộ sưu tập', url('/bo-suu-tap'))
            ->line('Đây là lịch nhắc tự động theo hạn chót bạn đã khai cho từng mẫu.');
    }
}
