<?php

namespace App\Enums;

/**
 * Genuine milestones of one answer, pushed to the browser as `status` SSE
 * events while the reply streams.
 *
 * Only work that is actually about to happen is announced, so the progress
 * copy the visitor reads is never a claim the server cannot back up.
 */
enum AnswerPhase: string
{
    /** The question arrived and is being understood (emitted first, as a keep-alive). */
    case Reading = 'reading';

    /** Approved documents are being searched for matching passages. */
    case Searching = 'searching';

    /** Context is ready and the model is composing the reply. */
    case Preparing = 'preparing';
}
