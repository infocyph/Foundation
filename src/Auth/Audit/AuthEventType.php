<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Audit;

enum AuthEventType: string
{
    case ACCESS_TOKEN_ISSUED = 'access_token_issued';

    case AUTHORIZATION_DENIED = 'authorization_denied';

    case DELEGATED_ACCESS_GRANTED = 'delegated_access_granted';

    case DELEGATED_ACCESS_REVOKED = 'delegated_access_revoked';

    case EMAIL_VERIFICATION_REQUESTED = 'email_verification_requested';

    case EMAIL_VERIFIED = 'email_verified';

    case IMPERSONATION_STARTED = 'impersonation_started';

    case IMPERSONATION_STOPPED = 'impersonation_stopped';

    case LOCKOUT_CLEARED = 'lockout_cleared';

    case LOCKOUT_TRIGGERED = 'lockout_triggered';

    case LOGIN_FAILURE = 'login_failure';

    case LOGIN_SUCCESS = 'login_success';

    case LOGOUT = 'logout';

    case MFA_CHALLENGED = 'mfa_challenged';

    case MFA_DISABLED = 'mfa_disabled';

    case MFA_ENROLLED = 'mfa_enrolled';

    case OAUTH_ACCESS_TOKEN_ISSUED = 'oauth_access_token_issued';

    case OAUTH_ACCESS_TOKEN_REVOKED = 'oauth_access_token_revoked';

    case OAUTH_AUTHORIZATION_APPROVED = 'oauth_authorization_approved';

    case OAUTH_AUTHORIZATION_DENIED = 'oauth_authorization_denied';

    case OAUTH_AUTHORIZATION_REVOKED = 'oauth_authorization_revoked';

    case OAUTH_AUTHORIZATION_CODE_CONSUMED = 'oauth_authorization_code_consumed';

    case OAUTH_AUTHORIZATION_CODE_EXPIRED = 'oauth_authorization_code_expired';

    case OAUTH_AUTHORIZATION_CODE_ISSUED = 'oauth_authorization_code_issued';

    case OAUTH_AUTHORIZATION_CODE_REPLAY = 'oauth_authorization_code_replay';

    case OAUTH_CLIENT_AUTH_FAILURE = 'oauth_client_auth_failure';

    case OAUTH_CLIENT_AUTH_SUCCESS = 'oauth_client_auth_success';

    case OAUTH_INTROSPECTION = 'oauth_introspection';

    case OAUTH_INVALID_REQUEST = 'oauth_invalid_request';

    case OAUTH_KEY_READINESS = 'oauth_key_readiness';

    case OAUTH_REFRESH_TOKEN_REUSE = 'oauth_refresh_token_reuse';

    case OAUTH_REFRESH_TOKEN_REVOKED = 'oauth_refresh_token_revoked';

    case OAUTH_REFRESH_TOKEN_ROTATED = 'oauth_refresh_token_rotated';

    case OAUTH_RATE_LIMITED = 'oauth_rate_limited';

    case PASSKEY_REGISTERED = 'passkey_registered';

    case PASSKEY_REMOVED = 'passkey_removed';

    case PASSKEY_USED = 'passkey_used';

    case PASSWORD_CHANGED = 'password_changed';

    case PASSWORD_RESET_COMPLETED = 'password_reset_completed';

    case PASSWORD_RESET_REQUESTED = 'password_reset_requested';

    case RECOVERY_CODE_USED = 'recovery_code_used';

    case REFRESH_TOKEN_ISSUED = 'refresh_token_issued';

    case REFRESH_TOKEN_REUSE_DETECTED = 'refresh_token_reuse_detected';

    case REFRESH_TOKEN_REVOKED = 'refresh_token_revoked';

    case REFRESH_TOKEN_ROTATED = 'refresh_token_rotated';

    case REMEMBER_TOKEN_ISSUED = 'remember_token_issued';

    case REMEMBER_TOKEN_REVOKED = 'remember_token_revoked';

    case SESSION_CREATED = 'session_created';

    case SESSION_EXPIRED = 'session_expired';

    case SESSION_REVOKED = 'session_revoked';
}
