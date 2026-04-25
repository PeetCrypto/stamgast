# Email System Implementation Plan

## Overview
This plan details the implementation of an email system for the STAMGAST Loyalty Platform, supporting multiple email providers (BREVO, SENDER.NET, Amazon SES), with role-based template management and secure SMTP configuration.

## 1. Project Structure
- Create `services/email` directory with subdirectories for providers and templates.
  - `services/email/providers/`: Contains provider-specific implementations (BrevoProvider.js, SenderNetProvider.js, AwsSesProvider.js).
  - `services/email/templates/`: Stores email templates (HTML/Text format).
  - `services/email/email.service.js`: Core service for sending emails.
- `config/email.js`: Global email configuration (provider selection, default settings).
- `models/email-config.model.js`: Database model for SMTP configuration.
- `models/email-template.model.js`: Database model for email templates.

## 2. Database Schema Changes
- **`email_config` table**:
  - `id`: Primary key (UUID)
  - `provider`: ENUM ('brevo', 'sender_net', 'aws_ses')
  - `smtp_host`: String
  - `smtp_port`: Integer
  - `smtp_user`: Encrypted String
  - `smtp_pass`: Encrypted String
  - `created_at`: Timestamp
  - `updated_at`: Timestamp

- **`email_templates` table**:
  - `id`: Primary key (UUID)
  - `type`: ENUM ('tenant_welcome', 'admin_invite', 'guest_confirmation', 'marketing')
  - `subject`: String
  - `content`: Text (HTML)
  - `tenant_id`: Foreign key to `tenants` table
  - `created_at`: Timestamp
  - `updated_at`: Timestamp

## 3. Email Service Layer
- **`EmailService` class**:
  - `sendEmail(to, subject, content, options)`: Sends email using configured provider.
  - Uses dependency injection to select provider based on `email_config`.
- **Provider Implementations**:
  - Each provider implements a common interface (e.g., `send()` method).
  - Example: `BrevoProvider` uses BREVO's API; `AwsSesProvider` uses AWS SDK.

## 4. Template Management
- **Superadmin**:
  - Can manage `tenant_welcome` templates (global tenant welcome email).
- **Admin (Manager)**:
  - Can manage `admin_invite`, `guest_confirmation`, and `marketing` templates.
  - Templates are tenant-specific (linked via `tenant_id`).

## 5. Workflow Details
### 5.1 New Tenant Creation (Superadmin)
- Trigger: Tenant created in Superadmin.
- Steps:
  1. Retrieve `tenant_welcome` template from `email_templates` for the new tenant.
  2. Generate password setup link with token.
  3. Send email from `no-reply@regulr.vip` with display name = Tenant name.
  4. Set reply-to to tenant's configured email address.

### 5.2 Admin/Bartender Creation (Admin)
- Trigger: Admin creates new admin/bartender.
- Steps:
  1. Retrieve `admin_invite` template for the tenant.
  2. Generate invitation link with token.
  3. Send email from `no-reply@regulr.vip` with display name = Tenant name.
  4. Set reply-to to tenant's configured email address.

### 5.3 Guest Registration
- Trigger: New guest registers.
- Steps:
  1. Send confirmation email (`guest_confirmation` template) with verification code.
  2. After verification, send password setup link email.

### 5.4 Marketing Emails
- Trigger: Admin initiates marketing campaign.
- Steps:
  1. Retrieve marketing template for the tenant.
  2. Send email from `no-reply@regulr.vip` with display name = Tenant name.
  3. Set reply-to to tenant's configured email address.

## 6. Security
- SMTP credentials stored encrypted in `email_config` table.
- Access to SMTP settings restricted to Superadmin role via RBAC.
- Environment variables for secrets (e.g., email service API keys) should be used where applicable.

## 7. Testing Strategy
- **Unit Tests**:
  - Test each provider's `send()` method with mock responses.
  - Verify template rendering with placeholder data.
- **Integration Tests**:
  - Test email sending in a test environment using a test SMTP server.
  - Validate role-based access for template management.

## 8. Deployment Steps
1. Update database schema with new tables.
2. Implement service layer and providers.
3. Create template management UI components for Superadmin/Admin roles.
4. Configure SMTP settings in Superadmin interface.
5. Test all email triggers in staging environment.

This plan provides a clear roadmap for implementation. Once approved, proceed with coding as per the outlined steps.