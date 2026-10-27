<?php

namespace App\Support;

use App\Models\EdgeRequest;
use App\Models\GymSession;
use App\Models\LiveTail;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * The models whose rows point at a domain by its domain_id: the core's, then any an add-on tags in
 * under TAG. When one domain merges into another (App\Actions\Domains\MergeDomains), as a www. domain
 * folds into its bare name, every one of their rows moves with it.
 */
class DomainRecords
{
    /**
     * The container tag add-ons' models register under.
     */
    public const string TAG = 'domains.records';

    /**
     * @var list<class-string<Model>>
     */
    private const array CORE = [
        EdgeRequest::class,
        GymSession::class,
        LiveTail::class,
    ];

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * @return list<class-string<Model>>
     */
    public function models(): array
    {
        $models = self::CORE;

        foreach ($this->container->tagged(self::TAG) as $model) {
            if ($model instanceof Model && ! in_array($model::class, $models, true)) {
                $models[] = $model::class;
            }
        }

        return $models;
    }
}
