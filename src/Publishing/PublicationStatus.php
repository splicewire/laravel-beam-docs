<?php

namespace Splicewire\Beam\Docs\Publishing;

enum PublicationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
