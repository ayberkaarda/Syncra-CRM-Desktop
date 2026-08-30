<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Ortak morph beyaz listesi — `taskable_type` (Task) ve `activityable_type`
 * (Activity) istemciden gelen KISA ADI (`deal`, `contact`, ...) taşır.
 *
 * Bunu doğrudan `"App\\Models\\" . $type` ile FQCN'e çevirmek sınıf
 * enjeksiyonudur — istemci autoload edilebilen HERHANGİ bir sınıfı hedef
 * gösterebilir. Aynı desen Faz 4'te `App\Broadcasting\ChannelRegistry`
 * (presence-record.{type} kanalı) ve Faz 5'te `App\Repositories\LogRepository::
 * SUBJECT_TYPE_MAP` (filter[subject_type]) için kuruldu; burada tutarlı
 * kalınıyor: sabit bir dizi lookup, class_exists() yok, string birleştirme
 * yok, case-folding yok.
 *
 * API her zaman KISA ADI kullanır — FQCN asla dışarı (response gövdesine)
 * sızmaz (bkz. shortName()).
 */
final class MorphTargets
{
    /**
     * @var array<string, class-string<Model>>
     */
    public const TARGETS = [
        'deal' => Deal::class,
        'lead' => Lead::class,
        'contact' => Contact::class,
        'company' => Company::class,
        'ticket' => Ticket::class,
    ];

    /**
     * Kısa ad -> tam sınıf adı. Beyaz listede yoksa null döner.
     */
    public static function resolve(?string $shortName): ?string
    {
        if (empty($shortName)) {
            return null;
        }

        return self::TARGETS[$shortName] ?? null;
    }

    /**
     * Tam sınıf adı -> kısa ad (ters çevrim). Beyaz listede yoksa null
     * döner — ham FQCN hiçbir zaman Resource üzerinden dışarı sızmaz.
     */
    public static function shortName(?string $fqcn): ?string
    {
        if (empty($fqcn)) {
            return null;
        }

        return array_search($fqcn, self::TARGETS, true) ?: null;
    }

    /**
     * Belirtilen kısa ad + id için kayıt gerçekten var mı? StoreTaskRequest /
     * StoreActivityRequest (ve update eşdeğerleri) bunu kullanarak var
     * olmayan bir hedefe görev/aktivite bağlanmasını (öksüz kayıt) engeller.
     */
    public static function exists(?string $shortName, mixed $id): bool
    {
        $fqcn = self::resolve($shortName);

        if ($fqcn === null || empty($id) || ! is_numeric($id)) {
            return false;
        }

        return $fqcn::query()->whereKey((int) $id)->exists();
    }

    /**
     * Hedefin insan-okunur etiketi — TaskResource/ActivityResource'un
     * `taskable.label` / `activityable.label` alanı.
     *
     * Model bazında farklı "isim" sütunları var (Deal: title, Company: name,
     * Contact: full_name accessor, Ticket: ticket_number + subject, Lead:
     * first_name/last_name — Lead'in name/title/full_name eşdeğeri bir
     * accessor'ı YOK, bkz. LeadResource'un aynı birleştirmeyi elle yaptığı
     * yer). Genel bir "hangi alan varsa onu al" sezgisiyle sniff etmek
     * yerine kısa ada göre AÇIK bir eşleme kullanılıyor: MorphTargets zaten
     * sadece 5 hedef tipini tanıyor, bu yüzden her biri için doğru sütunu
     * bilmek sniff etmekten daha güvenilir ve okunaklı.
     *
     * $model null ise (hedef silinmiş/yok) null döner — patlamaz.
     */
    public static function label(?string $shortName, ?Model $model): ?string
    {
        if ($model === null || $shortName === null) {
            return null;
        }

        return match ($shortName) {
            'deal' => $model->title ?? null,
            'company' => $model->name ?? null,
            'contact' => $model->full_name ?? null,
            'ticket' => self::ticketLabel($model),
            'lead' => self::leadLabel($model),
            default => null,
        };
    }

    private static function ticketLabel(Model $ticket): ?string
    {
        $number = $ticket->ticket_number ?? null;
        $subject = $ticket->subject ?? null;

        if ($number && $subject) {
            return "{$number} — {$subject}";
        }

        return $subject ?? $number ?? null;
    }

    private static function leadLabel(Model $lead): ?string
    {
        $fullName = trim(($lead->first_name ?? '').' '.($lead->last_name ?? ''));

        return $fullName !== '' ? $fullName : null;
    }
}
