<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\ImportLeadsRequest;
use App\Jobs\ImportLeadsJob;
use App\Models\Lead;
use App\Services\Leads\LeadImportService;
use App\Support\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Lead CSV toplu içe aktarma: şablon indirme, yükleme ve durum sorgulama.
 * İş mantığının tamamı LeadImportService/LeadCsvParser/ImportBatch'te yaşar
 * — bu controller ince kalır (yetkilendirme + dosya yaşam döngüsü + senkron/
 * kuyruk kararı).
 */
class LeadImportController extends Controller
{
    public function __construct(private readonly LeadImportService $service) {}

    /**
     * İndirilebilir CSV şablonu: başlık + iki örnek satır.
     *
     * UTF-8 BOM ile başlar (`\xEF\xBB\xBF`) — yoksa Excel dosyayı Windows-1254
     * gibi açar ve "Ayşe", "İnşaat" gibi Türkçe karakterler bozulur. Excel'in
     * kendisinin "CSV UTF-8" export'unda yaptığı da tam olarak bu.
     */
    public function template(): Response
    {
        Gate::authorize('import', Lead::class);

        $columns = [
            'first_name', 'last_name', 'email', 'phone', 'company_name',
            'position', 'source', 'status', 'score', 'notes',
        ];

        $rows = [
            ['Ayşe', 'Yılmaz', 'ayse.yilmaz@example.com', '+90 532 111 22 33', 'Yılmaz Holding', 'Satın Alma Müdürü', 'website', 'new', '65', 'İlk görüşme yapıldı, teklif bekleniyor.'],
            ['Mehmet', 'Demir', 'mehmet.demir@example.com', '0532 222 33 44', 'Demir İnşaat', 'Genel Müdür', 'referral', 'contacted', '40', 'Referans: Ahmet Kaya.'],
        ];

        $csv = "\xEF\xBB\xBF".$this->csvLine($columns)."\r\n";

        foreach ($rows as $row) {
            $csv .= $this->csvLine($row)."\r\n";
        }

        return response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="syncra-lead-sablonu.csv"',
        ]);
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function csvLine(array $fields): string
    {
        $escaped = array_map(function (string $field): string {
            if (preg_match('/[",\r\n]/', $field) === 1) {
                return '"'.str_replace('"', '""', $field).'"';
            }

            return $field;
        }, $fields);

        return implode(',', $escaped);
    }

    /**
     * CSV yükler; 500 satırın altındaysa senkron işleyip raporu doğrudan
     * döner, üstündeyse kuyruğa atıp 202 + `batch_id` döner (bkz.
     * LeadImportService::SYNC_ROW_THRESHOLD).
     */
    public function store(ImportLeadsRequest $request): JsonResponse
    {
        Gate::authorize('import', Lead::class);

        $uploaded = $request->file('file');

        // Rastgele isim (Str::uuid) — orijinal dosya adı hiçbir zaman dosya
        // sistemine yazılmaz (path traversal / script uzantısı gizleme
        // riski). 'local' diski storage/app altındadır, public DEĞİLDİR —
        // dosyaya URL üzerinden doğrudan erişilemez.
        $relativePath = $uploaded->storeAs('imports', Str::uuid()->toString().'.csv', 'local');
        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $handle = fopen($absolutePath, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Yüklenen dosya okunamadı.');
            }

            // Content-Type başlığına güvenilmez (ImportLeadsRequest zaten
            // mimes/mimetypes ile bunu doğruluyor); asıl doğrulama dosyanın
            // GERÇEK ilk satırının beklenen sütunları içerip içermediğidir.
            $this->service->readHeader($handle);

            if ($this->service->missingRequiredColumns() !== []) {
                fclose($handle);

                throw ValidationException::withMessages([
                    'file' => [
                        'CSV dosyasında zorunlu sütun(lar) eksik: '
                            .implode(', ', $this->service->missingRequiredColumns()).'.',
                    ],
                ]);
            }

            $totalRows = $this->service->countDataRows($handle);
            fclose($handle);

            $actor = $request->user();
            $ownerId = $request->ownerId() ?? $actor->id;
            $duplicateMode = $request->duplicateMode();

            $batch = ImportBatch::start(Str::uuid()->toString(), $actor->id, $totalRows);

            if ($totalRows < LeadImportService::SYNC_ROW_THRESHOLD) {
                $handle = fopen($absolutePath, 'rb');
                $this->service->readHeader($handle);
                $this->service->process($batch, $handle, $duplicateMode, $ownerId, $actor);
                fclose($handle);

                Storage::disk('local')->delete($relativePath);

                return response()->json(['data' => $batch->toArray()], Response::HTTP_OK);
            }

            ImportLeadsJob::dispatch($batch->id(), $relativePath, $duplicateMode, $ownerId, $actor->id);

            return response()->json([
                'data' => [
                    'batch_id' => $batch->id(),
                    'status' => $batch->status(),
                ],
            ], Response::HTTP_ACCEPTED);
        } catch (Throwable $e) {
            // Kuyruğa devredilen mutlu yol hariç HER çıkış (doğrulama hatası,
            // beklenmeyen istisna) geçici dosyayı temizler — public olmayan
            // storage/app/imports altında öksüz dosya birikmesin.
            Storage::disk('local')->delete($relativePath);

            throw $e;
        }
    }

    /**
     * Bir batch'in ilerleme/rapor durumunu döner. Yalnızca batch'i başlatan
     * kullanıcı okuyabilir.
     */
    public function status(string $batch): JsonResponse
    {
        Gate::authorize('import', Lead::class);

        $found = ImportBatch::find($batch);

        abort_if($found === null, Response::HTTP_NOT_FOUND);

        abort_if(! $found->belongsTo(request()->user()->id), Response::HTTP_FORBIDDEN);

        return response()->json(['data' => $found->toArray()]);
    }
}
