<?php

namespace App\Enums;

enum ActivityType: string
{
    /** The request was created (as a draft). */
    case Created = 'created';
    /** An employee edited the request's fields. */
    case Updated = 'updated';
    /** The request moved from one status to another. */
    case Transitioned = 'transitioned';
    /** Operations (re)assigned the owner. May also move the request to "assigned". */
    case Assigned = 'assigned';
    /** A comment was added to the discussion. */
    case Commented = 'commented';
}
