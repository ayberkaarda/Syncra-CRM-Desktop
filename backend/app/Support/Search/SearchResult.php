<?php

namespace App\Support\Search;

/**
 * Global arama (`GET /api/search`) sonuç satırı için değişmez DTO.
 *
 * 7 farklı modül (Deal/Lead/Contact/Company/Quote/Ticket/User) tamamen
 * farklı sütun kümelerine sahip; her modülün servis metodu kendi Eloquent
 * modelini bu ORTAK dört alana (başlık, ikincil satır, id, link) indirger.
 * `SearchResultResource` bu DTO'yu saran ince bir katmandır (bkz. o dosya).
 */
final class SearchResult implements \JsonSerializable
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $link,
    ) {}

    /**
     * @return array{type: string, id: int, title: string, subtitle: ?string, link: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'link' => $this->link,
        ];
    }
}
