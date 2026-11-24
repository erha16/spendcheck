<?php

namespace App\Models;

enum AdvanceSettlementItemStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case RevisionRequested = 'revision_requested';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
