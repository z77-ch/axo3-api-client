<?php

namespace Axo3\ApiClient;

/**
 * Outcome of one revalidation.
 *
 *  - Changed:   a new snapshot version was written — display data differs.
 *  - Unchanged: fetch succeeded and the content is identical — snapshot kept.
 *  - Failed:    fetch failed — warm fallback: the current snapshot is kept,
 *               details in the sync log.
 */
enum RevalidateResult
{
    case Changed;
    case Unchanged;
    case Failed;
}
