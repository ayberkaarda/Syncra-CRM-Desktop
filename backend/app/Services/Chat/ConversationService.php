<?php

namespace App\Services\Chat;

use App\Events\Chat\ConversationUpdated;
use App\Http\Resources\Chat\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Konuşma iş mantığı — üç tipin (dm / group / record) kuralları burada
 * yaşar. Controller ince kalır, yetki kararları ConversationPolicy'dedir.
 *
 * -----------------------------------------------------------------------------
 * GRUP YETKİLERİ İZİN DEĞİL, SAHİPLİK MODELİDİR
 * -----------------------------------------------------------------------------
 * Sohbet gruplarını 63 izinlik matrise bağlamak yanlış soruyu cevaplardı: bir
 * kullanıcının "grup yönetme" yetkisi rolünden değil, O GRUBU KURMUŞ olmasından
 * gelir. Rol matrisine `chat.group.manage` eklemek, Satış Müdürü'nün kendi
 * kurmadığı bir gruptan üye atabilmesi anlamına gelirdi. Bu yüzden yeni izin
 * EKLENMEDİ; `chat.use` özelliğin tamamını açar, geri kalanı `created_by`
 * belirler.
 *
 * -----------------------------------------------------------------------------
 * GRUP SAHİPSİZ KALMAZ
 * -----------------------------------------------------------------------------
 * Kurucu ayrıldığında `created_by` EN ESKİ üyeye (en küçük `joined_at`)
 * otomatik devrolur. Aksi halde grup dondurulurdu: kimse üye çıkaramaz, adını
 * değiştiremez, arşivleyemez. "Sahibi silinmiş kayıt" durumu, ürünün en uzun
 * ömürlü nesnelerinden biri olan grup sohbetinde kabul edilemez.
 */
class ConversationService
{
    /**
     * Liste ve detay yanıtlarında yüklenen ilişkiler.
     *
     * `latestMessage.attachment` de burada: liste satırındaki
     * `last_message_preview` dosya mesajlarında dosya adını gösterir ve bunu
     * konuşma başına ayrı bir sorguyla çekmek klasik N+1 olurdu.
     *
     * @var array<int, string>
     */
    protected const RELATIONS = ['users', 'conversable', 'latestMessage.attachment'];

    public function __construct(
        protected ChatReadState $readState,
    ) {}

    /**
     * `GET /api/conversations`.
     *
     * SIRALAMA: `last_message_at DESC`, boş olanlar (henüz mesajlaşılmamış
     * yeni konuşmalar) en sonda. `ORDER BY last_message_at DESC` tek başına
     * MySQL'de NULL'ları EN SONA değil en başa/sona sürücüye göre değişen
     * biçimde koyar; `IS NULL ASC` öncülü bunu belirli hâle getirir. `id DESC`
     * ise aynı saniyede oluşan iki konuşmada sayfalamanın kararlı kalması
     * içindir (kararsız sıralama, sayfa 2'de bir kaydın tekrar görünmesine
     * yol açar).
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(int $viewerId, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 30);
        $type = $filters['type'] ?? null;
        $q = $filters['q'] ?? null;

        return Conversation::query()
            ->with(self::RELATIONS)
            ->forMember($viewerId)
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->when($q !== null && $q !== '', function (Builder $query) use ($q, $viewerId) {
                $like = '%'.$q.'%';

                // Grup adı VEYA karşı tarafın adı/e-postası. `dm` konuşmaların
                // `name` kolonu boştur, dolayısıyla adıyla aranabilmesinin tek
                // yolu üye tablosuna bakmaktır. Arayan kişinin KENDİ adı
                // dışlanır — herkesin kendi adını yazınca tüm sohbetlerini
                // görmesi arama değil, gürültüdür.
                $query->where(function (Builder $inner) use ($like, $viewerId) {
                    $inner->where('name', 'like', $like)
                        ->orWhereHas('users', function (Builder $users) use ($like, $viewerId) {
                            $users->where('users.id', '<>', $viewerId)
                                ->where(function (Builder $name) use ($like) {
                                    $name->where('users.name', 'like', $like)
                                        ->orWhere('users.email', 'like', $like);
                                });
                        });
                });
            })
            ->orderByRaw('last_message_at IS NULL ASC')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Tek konuşmayı Resource'un beklediği ilişkilerle tazeler.
     */
    public function load(Conversation $conversation): Conversation
    {
        return $conversation->load(self::RELATIONS);
    }

    /**
     * `GET /api/conversations/unread-count`.
     *
     * @return array{total_unread: int, conversation_count: int}
     */
    public function unreadTotals(int $viewerId): array
    {
        return $this->readState->totalsFor($viewerId);
    }

    /**
     * `POST /api/conversations` — `dm` ya da `group`.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Conversation
    {
        $memberIds = array_values(array_unique(array_map('intval', $data['member_ids'] ?? [])));
        $creatorId = (int) $creator->getKey();

        if (($data['type'] ?? Conversation::TYPE_DM) === Conversation::TYPE_DM) {
            return $this->findOrCreateDirect($creatorId, (int) ($memberIds[0] ?? 0));
        }

        // Kurucu her zaman üyedir — istemcinin kendini listeye koymayı
        // unutması, kurduğu grubu göremediği bir duruma dönüşmemeli.
        $memberIds = array_values(array_unique(array_merge($memberIds, [$creatorId])));

        $conversation = DB::transaction(function () use ($data, $creatorId, $memberIds): Conversation {
            $conversation = Conversation::query()->create([
                'type' => Conversation::TYPE_GROUP,
                'name' => $data['name'],
                'created_by' => $creatorId,
            ]);

            $this->attachMembers($conversation, $memberIds);

            return $conversation;
        });

        return $this->load($conversation);
    }

    /**
     * `dm` için GET-OR-CREATE.
     *
     * Aynı iki kişi arasında ikinci bir dm AÇILAMAZ: açılabilseydi mesaj
     * geçmişi iki listeye bölünür ve kullanıcı "yazdığım mesaj nerede"
     * sorusuyla baş başa kalırdı — üstelik hangi konuşmanın açılacağı
     * istemcinin o anki durumuna bağlı, tekrarlanamaz bir davranış olurdu.
     *
     * YARIŞ KOŞULU: iki istemci aynı anda "sohbet başlat" derse iki ayrı
     * `INSERT` çakışabilir. Şema düzeyinde bunu engelleyecek bir kısıt yoktur
     * (benzersizlik bir pivot ÇİFTİ üzerindedir, tek satırda değil), bu yüzden
     * kritik bölge iki kullanıcının id'sinden türetilen KARARLI bir kilit adıyla
     * korunur — küçük/büyük sıraya sokulur ki (A,B) ve (B,A) aynı kilidi alsın.
     */
    public function findOrCreateDirect(int $userId, int $otherId): Conversation
    {
        $pair = [$userId, $otherId];
        sort($pair);

        $lock = Cache::lock(sprintf('chat:dm:%d:%d', $pair[0], $pair[1]), 10);

        return $lock->block(5, function () use ($userId, $otherId, $pair): Conversation {
            $existing = $this->findDirect($userId, $otherId);

            if ($existing !== null) {
                return $this->load($existing);
            }

            $conversation = DB::transaction(function () use ($userId, $pair): Conversation {
                $conversation = Conversation::query()->create([
                    'type' => Conversation::TYPE_DM,
                    'name' => null,
                    'created_by' => $userId,
                ]);

                $this->attachMembers($conversation, $pair);

                return $conversation;
            });

            return $this->load($conversation);
        });
    }

    /**
     * Tam olarak bu iki kişiden oluşan dm — `has('users', '=', 2)` şart:
     * yalnızca iki `whereHas` ile arasaydık, ikisini de İÇEREN üç kişilik bir
     * konuşma da eşleşirdi.
     */
    public function findDirect(int $userId, int $otherId): ?Conversation
    {
        return Conversation::query()
            ->where('type', Conversation::TYPE_DM)
            ->whereHas('users', fn (Builder $q) => $q->whereKey($userId))
            ->whereHas('users', fn (Builder $q) => $q->whereKey($otherId))
            ->has('users', '=', 2)
            ->first();
    }

    /**
     * `POST /api/conversations/for-record` — GET-OR-CREATE.
     *
     * Üyelik OTOMATİKTİR: kaydı görebilen kullanıcı sohbeti ilk açtığında üye
     * olur. Gerekçe, `type=record` sohbetin görünürlüğünün pivot değil KAYDIN
     * KENDİ izni olmasıdır (bkz. RecordChatRegistry); üyeliği ayrıca elle
     * istemek, kullanıcıyı göreceği bir panele "katılmak" için ikinci bir
     * tıklamaya zorlardı.
     */
    public function forRecord(string $shortType, int $recordId, User $user): Conversation
    {
        $fqcn = RecordChatRegistry::resolve($shortType);
        $lock = Cache::lock(sprintf('chat:record:%s:%d', $shortType, $recordId), 10);

        return $lock->block(5, function () use ($fqcn, $recordId, $user): Conversation {
            $conversation = Conversation::query()
                ->where('type', Conversation::TYPE_RECORD)
                ->where('conversable_type', $fqcn)
                ->where('conversable_id', $recordId)
                ->first();

            if ($conversation === null) {
                $conversation = Conversation::query()->create([
                    'type' => Conversation::TYPE_RECORD,
                    'name' => null,
                    'conversable_type' => $fqcn,
                    'conversable_id' => $recordId,
                    'created_by' => $user->getKey(),
                ]);
            }

            $this->ensureMember($conversation, (int) $user->getKey());

            return $this->load($conversation);
        });
    }

    /**
     * Pivot satırı yoksa açar. Varsa DOKUNMAZ — `firstOrCreate` benzeri
     * davranış, mevcut okunmamış sayacını ve susturma tercihini sıfırlamamak
     * için şart.
     */
    public function ensureMember(Conversation $conversation, int $userId): void
    {
        DB::table('conversation_user')->insertOrIgnore([
            'conversation_id' => $conversation->getKey(),
            'user_id' => $userId,
            'unread_count' => 0,
            'is_muted' => false,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * `PATCH /api/conversations/{conversation}` — yalnızca grup adı.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Conversation $conversation, array $data): Conversation
    {
        $conversation->fill(['name' => $data['name']])->save();

        $conversation = $this->load($conversation);

        $this->broadcastUpdated($conversation);

        return $conversation;
    }

    /**
     * `DELETE /api/conversations/{conversation}` — arşivleme (soft delete).
     *
     * Mesajlar SİLİNMEZ: `messages.conversation_id` FK'si `cascadeOnDelete`
     * taşır ama soft delete gerçek bir `DELETE` üretmediği için tetiklenmez —
     * Faz 9'da PriceList için verilen kararla aynı desen (soft delete çocuk
     * kayıtları korur, cascade yalnızca `forceDelete`'te). Grup geri
     * yüklenirse geçmişi yerinde bulunur.
     */
    public function delete(Conversation $conversation): void
    {
        $conversation->delete();
    }

    /**
     * `POST /api/conversations/{conversation}/members`.
     *
     * Eklenecek kullanıcının `chat.use` iznine sahip olması ŞARTI burada
     * DEĞİL, StoreMemberRequest içinde uygulanır (ihlalde 422). Gerekçe:
     * bu bir YETKİ sorusu değil, GÖNDERİLEN VERİNİN geçerliliği sorusudur —
     * "sen ekleyemezsin" değil, "bu kişi eklenemez". Policy 403 üretirdi ve
     * arayüz hatayı üye seçme alanının altında gösteremezdi.
     *
     * @param  array<int, int>  $userIds
     */
    public function addMembers(Conversation $conversation, array $userIds): Conversation
    {
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            $this->ensureMember($conversation, $userId);
        }

        $conversation = $this->load($conversation);

        $this->broadcastUpdated($conversation);

        return $conversation;
    }

    /**
     * `DELETE /api/conversations/{conversation}/members/{user}` — yalnızca
     * kurucu (ConversationPolicy::removeMember).
     */
    public function removeMember(Conversation $conversation, int $userId): Conversation
    {
        if ((int) $conversation->created_by === $userId) {
            // Kurucunun kendini çıkarması, sahipliğin devri anlamına gelir ve
            // o iş `leave()`'in işidir. Aynı yan etkiyi iki uca dağıtmak,
            // birinde unutulduğunda sahipsiz grup üretirdi.
            throw ValidationException::withMessages([
                'user' => 'Grubun kurucusu üyelikten çıkarılamaz; gruptan ayrılma ucunu kullanın.',
            ]);
        }

        $conversation->users()->detach($userId);

        $conversation = $this->load($conversation);

        $this->broadcastUpdated($conversation);

        return $conversation;
    }

    /**
     * `POST /api/conversations/{conversation}/leave`.
     *
     * Kurucu ayrılırsa sahiplik EN ESKİ üyeye devrolur. Son üye de ayrılırsa
     * konuşma arşivlenir — üyesiz bir grup hiçbir listede görünmez ve yalnızca
     * yer kaplar.
     */
    public function leave(Conversation $conversation, int $userId): void
    {
        DB::transaction(function () use ($conversation, $userId) {
            $conversation->users()->detach($userId);

            if ((int) $conversation->created_by !== $userId) {
                return;
            }

            $successor = DB::table('conversation_user')
                ->where('conversation_id', $conversation->getKey())
                // `joined_at` null olabilir (Faz 3 demo verisi); null'lar en
                // sona itilir, yoksa MySQL onları "en eski" sayar.
                ->orderByRaw('joined_at IS NULL ASC')
                ->orderBy('joined_at')
                ->orderBy('id')
                ->first();

            if ($successor === null) {
                $conversation->delete();

                return;
            }

            $conversation->forceFill(['created_by' => (int) $successor->user_id])->save();
        });

        if ($conversation->trashed()) {
            return;
        }

        $this->broadcastUpdated($this->load($conversation));
    }

    /**
     * `PATCH /api/conversations/{conversation}/mute`.
     *
     * Yalnızca isteği yapan kullanıcının kendi pivot satırını etkiler —
     * susturma kişiseldir, konuşmanın özelliği değildir.
     */
    public function setMuted(Conversation $conversation, int $userId, bool $muted): Conversation
    {
        DB::table('conversation_user')
            ->where('conversation_id', $conversation->getKey())
            ->where('user_id', $userId)
            ->update(['is_muted' => $muted, 'updated_at' => now()]);

        return $this->load($conversation->refresh());
    }

    /**
     * @param  array<int, int>  $userIds
     */
    protected function attachMembers(Conversation $conversation, array $userIds): void
    {
        $now = now();
        $payload = [];

        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            $payload[$userId] = ['joined_at' => $now];
        }

        $conversation->users()->attach($payload);
    }

    /**
     * Ad/üye değişimini kanaldaki herkese duyurur.
     *
     * `$viewerId: null` — gövdedeki `unread_count` / `is_muted` alanları
     * kişiye özel olduğu için bu yayında bağlayıcı değildir; gerekçe ve
     * arayüz sözleşmesi ConversationUpdated dokümanındadır.
     */
    protected function broadcastUpdated(Conversation $conversation): void
    {
        broadcast(new ConversationUpdated(
            (int) $conversation->getKey(),
            ConversationResource::payload($conversation, null),
        ));
    }
}
