<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | Target size for a single chunk of extracted PDF text. Chunks are built
    | sentence-by-sentence so words and sentences are never split mid-way, and
    | a small tail of the previous chunk is carried forward to keep context
    | continuous across chunk boundaries.
    |
    */

    'chunk_size' => (int) env('RAG_CHUNK_SIZE', 500),

    'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 50),

    'chunk_max' => (int) env('RAG_CHUNK_MAX', 620),

    'chunk_min' => (int) env('RAG_CHUNK_MIN', 40),

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    'top_k' => (int) env('RAG_TOP_K', 4),

    'min_similarity' => (float) env('RAG_MIN_SIMILARITY', 0.15),

    /*
    |--------------------------------------------------------------------------
    | Hybrid Retrieval
    |--------------------------------------------------------------------------
    |
    | Vector search understands paraphrase but misses rare literal terms;
    | keyword search does the opposite. Both rankings are fused by position
    | (reciprocal rank fusion) rather than by score, because the two scores
    | are not comparable. Set hybrid to false to use vectors alone.
    |
    */

    'hybrid' => (bool) env('RAG_HYBRID', true),

    'rrf_k' => (int) env('RAG_RRF_K', 60),

    'retrieval_pool' => (int) env('RAG_RETRIEVAL_POOL', 20),

    'context_neighbour_chars' => (int) env('RAG_CONTEXT_NEIGHBOUR_CHARS', 220),

    /*
    | A lexical hit is trusted on its own when it scores at least this share
    | of the best keyword match, so a rare exact term ("Razorpay") is never
    | discarded just because it embeds close to nothing.
    */

    'keyword_confidence' => (float) env('RAG_KEYWORD_CONFIDENCE', 0.5),

    'max_chunks' => (int) env('RAG_MAX_CHUNKS', 5000),

    'max_document_tokens' => (int) env('RAG_MAX_DOC_TOKENS', 4000000),

    /*
    |--------------------------------------------------------------------------
    | Conversation Context
    |--------------------------------------------------------------------------
    |
    | How much of the conversation is replayed to the model, and how much of
    | each retrieved chunk is quoted back inside <context>.
    |
    */

    'history_messages' => (int) env('RAG_HISTORY_MESSAGES', 6),

    'history_chars' => (int) env('RAG_HISTORY_CHARS', 3000),

    'snippet_chars' => (int) env('RAG_SNIPPET_CHARS', 400),

    /*
    |--------------------------------------------------------------------------
    | Whole-Document Summary
    |--------------------------------------------------------------------------
    |
    | Map-reduce over the document: each slice is summarised on its own, then
    | the notes are condensed. Slices keep each prompt inside a small local
    | model's context window.
    |
    */

    'summary_slices' => (int) env('RAG_SUMMARY_SLICES', 6),

    'summary_slice_chars' => (int) env('RAG_SUMMARY_SLICE_CHARS', 2400),

    'summary_combine_chars' => (int) env('RAG_SUMMARY_COMBINE_CHARS', 3200),

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | text-embedding-3-small returns a 1536 dimension vector. Vectors are
    | L2-normalised on write so retrieval is a pure dot product.
    |
    | ai_driver accepts "openai" or "fake". Leave it unset to auto-detect:
    | "openai" when an API key is configured, "fake" otherwise so a fresh
    | install can demo the whole pipeline offline.
    |
    */

    'ai_driver' => env('RAG_AI_DRIVER'),

    'embedding_dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1536),

    'embedding_batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 64),

    /*
    |--------------------------------------------------------------------------
    | Query Embedding Cache
    |--------------------------------------------------------------------------
    |
    | How long an embedding computed for one visitor's question is reused for
    | the next visitor asking the same thing. It only depends on the model and
    | the text, so a short cache costs nothing but the round trip.
    |
    */

    'query_embedding_ttl' => (int) env('RAG_QUERY_EMBEDDING_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Response streaming
    |--------------------------------------------------------------------------
    |
    | Some shared hosts buffer output and swallow server-sent events. Flip
    | this to false to fall back to a single JSON response.
    |
    */

    'stream_enabled' => (bool) env('RAG_STREAM_ENABLED', true),

];
