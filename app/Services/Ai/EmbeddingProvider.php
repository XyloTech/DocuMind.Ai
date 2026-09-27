<?php

namespace App\Services\Ai;

use App\Exceptions\EmbeddingException;

interface EmbeddingProvider
{
    /**
     * Embed one batch of texts, preserving input order.
     *
     * Vectors are returned raw; L2 normalization is the caller's job.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws EmbeddingException
     */
    public function embed(array $texts): array;

    /**
     * Human readable driver name for logs and the admin panel.
     */
    public function label(): string;
}
