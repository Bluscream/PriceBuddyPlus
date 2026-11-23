<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Dto\SearchWatchEventDto;
use App\Enums\SearchWatchEventKind;
use App\Filament\Resources\SearchWatchResource;
use App\Models\SearchWatch;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\NotificationsHelper;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Pushover\PushoverMessage;

/**
 * Tells a user what a search watch found in one check: new matching listings and price drops.
 *
 * One event links straight to the listing; several are summarised and link to the watch.
 * Reuses every channel configured for price alerts.
 */
class SearchWatchNotification extends Notification
{
    private const int MAX_LINES = 5;

    /**
     * @param  list<SearchWatchEventDto>  $events
     */
    public function __construct(protected SearchWatch $watch, protected array $events) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        $channels = NotificationsHelper::getEnabledChannels($notifiable)->push('database')->all();

        return \array_values(\array_filter($channels, \is_string(...)));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->getTitle());
        foreach ($this->events as $event) {
            $mail->line($this->describe($event));
        }

        return $mail->action(__('Open listing'), $this->getUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return DatabaseNotification::make()
            ->title($this->getTitle())
            ->body($this->getSummary())
            ->status('info')
            ->actions([
                Action::make('open')
                    ->url($this->getUrl(), true)
                    ->label(\count($this->events) === 1 ? __('Open listing') : __('Open watch')),
            ])
            ->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->getTitle(),
            'summary' => $this->getSummary(),
            'url' => $this->getUrl(),
            'search_watch_id' => $this->watch->id,
        ];
    }

    public function toPushover(object $notifiable): PushoverMessage
    {
        return PushoverMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl(), __('Open'));
    }

    public function toGotify(object $notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    public function toApprise(object $notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    public function toTelegram(object $notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    public function toDiscord(object $notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    public function toNtfy(object $notifiable): GenericNotificationMessage
    {
        return $this->genericMessage();
    }

    public function getTitle(): string
    {
        if (\count($this->events) === 1) {
            $event = $this->events[0];

            return match ($event->kind) {
                SearchWatchEventKind::NewListing => __('New on :watch: :price', ['watch' => $this->watch->name, 'price' => $this->price($event->listing->price, $event->listing->price_text)]),
                SearchWatchEventKind::PriceDrop => __('Price drop on :watch: :old → :new', [
                    'watch' => $this->watch->name,
                    'old' => $this->price($event->oldPrice, null),
                    'new' => $this->price($event->listing->price, $event->listing->price_text),
                ]),
            };
        }

        return __(':count updates on :watch', ['count' => \count($this->events), 'watch' => $this->watch->name]);
    }

    public function getSummary(): string
    {
        $lines = \array_map($this->describe(...), \array_slice($this->events, 0, self::MAX_LINES));
        $more = \count($this->events) - self::MAX_LINES;
        if ($more > 0) {
            $lines[] = __('…and :count more.', ['count' => $more]);
        }

        return \implode("\n", $lines);
    }

    public function getUrl(): string
    {
        return \count($this->events) === 1
            ? $this->events[0]->listing->url
            : SearchWatchResource::getUrl('edit', ['record' => $this->watch]);
    }

    protected function describe(SearchWatchEventDto $event): string
    {
        $listing = $event->listing;
        $where = $listing->location !== null
            ? ' · '.$listing->location.($listing->distance_km !== null ? ' ('.$listing->distance_km.' km)' : '')
            : '';
        $price = match ($event->kind) {
            SearchWatchEventKind::NewListing => $this->price($listing->price, $listing->price_text),
            SearchWatchEventKind::PriceDrop => $this->price($event->oldPrice, null).' → '.$this->price($listing->price, $listing->price_text),
        };

        return $price.' · '.$listing->title.$where;
    }

    protected function price(?float $amount, ?string $text): string
    {
        if ($text !== null && $text !== '') {
            return $text;
        }

        return $amount !== null ? \number_format($amount, $amount === \floor($amount) ? 0 : 2, ',', '.').' €' : __('no price');
    }

    protected function genericMessage(): GenericNotificationMessage
    {
        return GenericNotificationMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl())
            ->priority(5);
    }
}
