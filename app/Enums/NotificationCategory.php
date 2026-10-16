<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case AVAILABILITY = 'availability';
    case QUEUE = 'queue';
    case FAVORITE = 'favorite';
    case SYSTEM = 'system';
    case SECURITY = 'security';
    case ORDER = 'order';
    // Campanhas de notificações (Setembro 2026) — cada uma pode ser
    // desligada à parte pelo utilizador (preferências de notificações).
    case REMINDER = 'reminder';
    case TIP = 'tip';
    case PROMO = 'promo';
    // Vizinhos KeruGás (Outubro 2026): menções e respostas.
    case COMMUNITY = 'community';
}
