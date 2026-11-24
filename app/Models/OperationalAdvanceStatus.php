<?php

namespace App\Models;

enum OperationalAdvanceStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case RevisionRequested = 'revision_requested';
    case Approved = 'approved';
    case Disbursed = 'disbursed';
    case SettlementInReview = 'settlement_in_review';
    case PartiallyAccounted = 'partially_accounted';
    case ReturnDue = 'return_due';
    case ReimbursementDue = 'reimbursement_due';
    case Settled = 'settled';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
