<?php

namespace App\Models;

use App\Support\ActivityLogging\LogsCrmActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

// Etiket — taggables pivot tablosu üzerinden çeşitli kayıt tiplerine (contact, company, deal, lead ...) bağlanır.
class Tag extends Model
{
    use HasFactory, LogsCrmActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'color',
    ];

    // M1'in modelleri tam nitelikli isimle referans alınıyor (henüz oluşturulmamış olabilir).
    public function contacts(): MorphToMany
    {
        return $this->morphedByMany(Contact::class, 'taggable');
    }

    public function companies(): MorphToMany
    {
        return $this->morphedByMany(Company::class, 'taggable');
    }

    public function deals(): MorphToMany
    {
        return $this->morphedByMany(Deal::class, 'taggable');
    }

    public function leads(): MorphToMany
    {
        return $this->morphedByMany(Lead::class, 'taggable');
    }
}
