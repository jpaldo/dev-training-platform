<?php

namespace App\Enums;

/**
 * How urgent a participant notification is (stored in `participant_notifications.priority`, default `normal`).
 * Revision requests and booked mentor interviews are `High`; every other notification is `Normal`.
 */
enum ParticipantNotificationPriority: string
{
    case Normal = 'normal';
    case High = 'high';
}
