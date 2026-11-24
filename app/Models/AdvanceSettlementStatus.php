<?php

namespace App\Models;

enum AdvanceSettlementStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Completed = 'completed';
    case RevisionRequested = 'revision_requested';
}
