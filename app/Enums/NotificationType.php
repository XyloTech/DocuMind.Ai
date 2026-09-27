<?php

namespace App\Enums;

/**
 * Every event the platform can raise. The pair (category, severity) plus the
 * essential flag is fixed per type so preferences and grouping stay
 * consistent no matter where the event is emitted from.
 */
enum NotificationType: string
{
    // Conversations & assistant outcomes
    case ConversationCreated = 'conversation.created';
    case ConversationReplied = 'conversation.replied';
    case ConversationEscalated = 'conversation.escalated';
    case AiSucceeded = 'ai.succeeded';
    case AiFailed = 'ai.failed';

    // Knowledge ingestion
    case KnowledgeUploaded = 'knowledge.uploaded';
    case KnowledgeProcessed = 'knowledge.processed';
    case KnowledgeFailed = 'knowledge.failed';

    // Widget configuration & usage
    case WidgetUpdated = 'widget.updated';
    case WidgetKeyRotated = 'widget.key_rotated';
    case WidgetQuotaReached = 'widget.quota_reached';

    // Visitor leads
    case LeadCaptured = 'lead.captured';

    // Team & workspace
    case TeamInvited = 'team.invited';
    case TeamInvitationAccepted = 'team.invitation_accepted';
    case TeamRoleChanged = 'team.role_changed';

    // Billing & usage
    case CreditsChanged = 'billing.credits_changed';
    case LowCredits = 'billing.low_credits';

    // Security & administration
    case NewSignIn = 'security.new_sign_in';
    case AdminAction = 'security.admin_action';

    // Platform
    case SystemNotice = 'system.notice';
    case IntegrationError = 'system.integration_error';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::ConversationCreated,
            self::ConversationReplied,
            self::ConversationEscalated,
            self::AiSucceeded,
            self::AiFailed => NotificationCategory::Conversation,

            self::KnowledgeUploaded,
            self::KnowledgeProcessed,
            self::KnowledgeFailed => NotificationCategory::Knowledge,

            self::WidgetUpdated,
            self::WidgetKeyRotated,
            self::WidgetQuotaReached => NotificationCategory::Widget,

            self::LeadCaptured => NotificationCategory::Lead,

            self::TeamInvited,
            self::TeamInvitationAccepted,
            self::TeamRoleChanged => NotificationCategory::Team,

            self::CreditsChanged,
            self::LowCredits => NotificationCategory::Billing,

            self::NewSignIn,
            self::AdminAction => NotificationCategory::Security,

            self::SystemNotice,
            self::IntegrationError => NotificationCategory::System,
        };
    }

    public function severity(): NotificationSeverity
    {
        return match ($this) {
            self::ConversationCreated,
            self::ConversationReplied,
            self::KnowledgeUploaded,
            self::WidgetUpdated,
            self::TeamInvited,
            self::NewSignIn,
            self::SystemNotice => NotificationSeverity::Info,

            self::AiSucceeded,
            self::KnowledgeProcessed,
            self::TeamInvitationAccepted,
            self::LeadCaptured => NotificationSeverity::Success,

            self::ConversationEscalated,
            self::WidgetKeyRotated,
            self::WidgetQuotaReached,
            self::TeamRoleChanged,
            self::LowCredits => NotificationSeverity::Warning,

            self::AiFailed,
            self::KnowledgeFailed,
            self::AdminAction,
            self::IntegrationError => NotificationSeverity::Error,

            self::CreditsChanged => NotificationSeverity::Info,
        };
    }

    /**
     * Essential notifications ignore the per-category and non-essential
     * opt-outs: security, billing and destructive admin actions always
     * reach the account owner in-app.
     */
    public function essential(): bool
    {
        return match ($this) {
            self::TeamInvited,
            self::TeamRoleChanged,
            self::CreditsChanged,
            self::NewSignIn,
            self::AdminAction,
            self::IntegrationError => true,

            default => false,
        };
    }

    /**
     * Types eligible for the optional email channel when the recipient has
     * opted in. Day-to-day activity (conversations, uploads) stays in-app
     * only — it would turn any real inbox into a second notification centre.
     */
    public function emailCapable(): bool
    {
        return match ($this) {
            self::KnowledgeFailed,
            self::AiFailed,
            self::WidgetQuotaReached,
            self::TeamInvited,
            self::TeamInvitationAccepted,
            self::TeamRoleChanged,
            self::CreditsChanged,
            self::LowCredits,
            self::NewSignIn,
            self::AdminAction,
            self::SystemNotice,
            self::IntegrationError => true,

            default => false,
        };
    }
}
