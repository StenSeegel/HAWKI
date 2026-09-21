<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class MailTemplateSeeder extends Seeder
{

    public function run(): void
    {
        // Use upsert to handle existing templates (update or insert)
        $templates = [
            // Welcome Templates
            [
                'type' => 'welcome',
                'language' => 'en',
                'description' => 'Welcome email for new users',
                'subject' => 'Welcome to {{app_name}} - Your AI Journey Begins!',
                'body' => $this->getWelcomeTemplateEn(),
            ],
            [
                'type' => 'welcome',
                'language' => 'de',
                'description' => 'Willkommens-E-Mail für neue Benutzer',
                'subject' => 'Willkommen bei {{app_name}} - Ihre KI-Reise beginnt!',
                'body' => $this->getWelcomeTemplateDe(),
            ],

            // OTP Templates
            [
                'type' => 'otp',
                'language' => 'en',
                'description' => 'E-mail confirmation code',
                'subject' => 'Confirm your email address for {{app_name}}',
                'body' => $this->getOtpTemplateEn(),
            ],
            [
                'type' => 'otp',
                'language' => 'de',
                'description' => 'E-Mail-Bestätigungscode',
                'subject' => 'Bestätigen Sie Ihre E-Mail-Adresse für {{app_name}}',
                'body' => $this->getOtpTemplateDe(),
            ],

            // Invitation Templates
            [
                'type' => 'invitation',
                'language' => 'en',
                'description' => 'Group chat invitation email',
                'subject' => 'Group Chat Invitation - {{room_name}}',
                'body' => $this->getInvitationTemplateEn(),
            ],
            [
                'type' => 'invitation',
                'language' => 'de',
                'description' => 'Gruppen-Chat Einladungs-E-Mail',
                'subject' => 'Gruppen-Chat Einladung - {{room_name}}',
                'body' => $this->getInvitationTemplateDe(),
            ],

            // Notification Templates
            [
                'type' => 'notification',
                'language' => 'en',
                'description' => 'General notification email',
                'subject' => '{{app_name}} Notification',
                'body' => $this->getNotificationTemplateEn(),
            ],
            [
                'type' => 'notification',
                'language' => 'de',
                'description' => 'Allgemeine Benachrichtigungs-E-Mail',
                'subject' => '{{app_name}} Benachrichtigung',
                'body' => $this->getNotificationTemplateDe(),
            ],

            // User Approval Granted Templates
            [
                'type' => 'approval_granted',
                'language' => 'en',
                'description' => 'Account approval granted notification',
                'subject' => 'Your {{app_name}} Account Has Been Approved',
                'body' => $this->getApprovalGrantedTemplateEn(),
            ],
            [
                'type' => 'approval_granted',
                'language' => 'de',
                'description' => 'Benachrichtigung über erteilte Kontogenehmigung',
                'subject' => 'Ihr {{app_name}}-Account wurde freigeschaltet',
                'body' => $this->getApprovalGrantedTemplateDe(),
            ],

            // Approval Pending Templates
            [
                'type' => 'approval_pending',
                'language' => 'en',
                'description' => 'Account pending approval notification',
                'subject' => 'Your {{app_name}} Account is Pending Approval',
                'body' => $this->getApprovalPendingTemplateEn(),
            ],
            [
                'type' => 'approval_pending',
                'language' => 'de',
                'description' => 'Benachrichtigung über ausstehende Kontogenehmigung',
                'subject' => 'Ihr {{app_name}}-Account wurde erfolgreich beantragt',
                'body' => $this->getApprovalPendingTemplateDe(),
            ],

            // Approval Revoked Templates
            [
                'type' => 'approval_revoked',
                'language' => 'en',
                'description' => 'Account approval revoked notification',
                'subject' => 'Your {{app_name}} Account Access Has Been Revoked',
                'body' => $this->getApprovalRevokedTemplateEn(),
            ],
            [
                'type' => 'approval_revoked',
                'language' => 'de',
                'description' => 'Benachrichtigung über widerrufene Kontogenehmigung',
                'subject' => 'Ihr {{app_name}}-Zugang wurde widerrufen',
                'body' => $this->getApprovalRevokedTemplateDe(),
            ],
        ];

        // Add timestamps to each template
        foreach ($templates as &$template) {
            $template['created_at'] = now();
            $template['updated_at'] = now();
        }

        // Use upsert to prevent duplicate entry errors
        // Updates existing templates, inserts new ones
        \DB::table('mail_templates')->upsert(
            $templates,
            ['type', 'language'], // Unique key columns
            ['description', 'subject', 'body', 'updated_at'] // Columns to update
        );
    }

    private function getWelcomeTemplateEn(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Welcome to {{app_name}}! 🎉</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hello {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    We\'re thrilled to have you join our community of researchers, students, and educators who are exploring the possibilities of generative AI in academic environments.
                </p>

                <div style="background: #dcfce7; border: 1px solid #16a34a; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #15803d;">Your account is now active!</strong><br>
                    <span style="color: #166534;">You can start using HAWKI\'s powerful AI features right away.</span>
                </div>

                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #856404;">Important: Your {{app_name}} Backup Code</strong><br>
                    <span style="color: #856404;">Please save this backup code securely. You will need it to unlock a new device for {{app_name}}:</span><br>
                    <div style="background: #fff; padding: 12px; margin: 12px 0; border-radius: 6px; text-align: center;">
<code style="font-family: \'Courier New\', monospace; font-size: 16px; font-weight: bold; letter-spacing: 2px; color: #2c3e50; background: transparent; padding: 0; border: none; display: inline-block; user-select: all; -webkit-user-select: all;">{{backup_hash}}</code>
                    </div>
                    <small style="color: #856404;">Store this code in a safe place. Do not share it with anyone.</small>
                </div>

                <h3 style="color: #1f2937; margin: 24px 0 16px 0;">What can you do with {{app_name}}?</h3>
                
                <ul style="color: #64748b; margin: 16px 0 24px 20px; line-height: 1.7;">
                    <li><strong>1:1 AI Conversations:</strong> Have private, encrypted conversations with advanced AI models</li>
                    <li><strong>Group Chat Rooms:</strong> Collaborate with colleagues in AI-enhanced group discussions</li>
                    <li><strong>Multi-Model Support:</strong> Access various AI models including OpenAI, Google, and local options</li>
                    <li><strong>Privacy-First Design:</strong> Your conversations are protected with end-to-end encryption</li>
                    <li><strong>Academic Focus:</strong> Tools and features designed specifically for university environments</li>
                </ul>

                <div style="text-align: center; margin: 32px 0;">
                    <a href="{{app_url}}" style="background: #2563eb; color: white; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; display: inline-block;">
                        Start Using {{app_name}}
                    </a>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    If you have any questions or need assistance, don\'t hesitate to reach out to our support team at <a href="mailto:{{support_email}}" style="color: #2563eb; text-decoration: none;">{{support_email}}</a> or explore <a href="https://www.hawki.info/" target="_blank" style="color: #2563eb; text-decoration: none;">our documentation</a>.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    Welcome aboard!<br>
                    <strong>The {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getWelcomeTemplateDe(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Willkommen bei {{app_name}}! 🎉</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hallo {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Wir freuen uns sehr, Sie in unserer Gemeinschaft von Forschern, Studenten und Lehrenden begrüßen zu dürfen, die die Möglichkeiten generativer KI in universitären Umgebungen erkunden.
                </p>

                <div style="background: #dcfce7; border: 1px solid #16a34a; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #15803d;">Ihr Konto ist jetzt aktiv!</strong><br>
                    <span style="color: #166534;">Sie können sofort mit der Nutzung von {{app_name}}s leistungsstarken KI-Funktionen beginnen.</span>
                </div>

                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #856404;">Wichtig: Ihr {{app_name}}-Wiederherstellungs-Code</strong><br>
                    <span style="color: #856404;">Bitte speichern Sie diesen Backup-Code sicher. Sie benötigen ihn, um ein neues Gerät für {{app_name}} freizuschalten:</span><br>
                    <div style="background: #fff; padding: 12px; margin: 12px 0; border-radius: 6px; text-align: center;">
<code style="font-family: \'Courier New\', monospace; font-size: 16px; font-weight: bold; letter-spacing: 2px; color: #2c3e50; background: transparent; padding: 0; border: none; display: inline-block; user-select: all; -webkit-user-select: all;">{{backup_hash}}</code>
                    </div>
                    <small style="color: #856404;">Bewahren Sie diesen Code an einem sicheren Ort auf. Teilen Sie ihn niemals mit anderen.</small>
                </div>

                <h3 style="color: #1f2937; margin: 24px 0 16px 0;">Was können Sie mit {{app_name}} machen?</h3>
                
                <ul style="color: #64748b; margin: 16px 0 24px 20px; line-height: 1.7;">
                    <li><strong>1:1 KI-Unterhaltungen:</strong> Private, verschlüsselte Gespräche mit fortschrittlichen KI-Modellen</li>
                    <li><strong>Gruppen-Chat-Räume:</strong> Zusammenarbeit mit Kollegen in KI-unterstützten Gruppendiskussionen</li>
                    <li><strong>Multi-Modell-Unterstützung:</strong> Zugang zu verschiedenen KI-Modellen einschließlich OpenAI, Google und lokalen Optionen</li>
                    <li><strong>Datenschutz-orientiertes Design:</strong> Ihre Unterhaltungen sind durch End-to-End-Verschlüsselung geschützt</li>
                    <li><strong>Akademischer Fokus:</strong> Tools und Funktionen speziell für universitäre Umgebungen entwickelt und in stetiger Weiterentwicklung</li>
                </ul>

                <div style="text-align: center; margin: 32px 0;">
                    <a href="{{app_url}}" style="background: #2563eb; color: white; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; display: inline-block;">
                        {{app_name}} nutzen
                    </a>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Wenn Sie Fragen haben oder Unterstützung benötigen, zögern Sie nicht, unser Support-Team unter <a href="mailto:{{support_email}}" style="color: #2563eb; text-decoration: none;">{{support_email}}</a> zu kontaktieren oder <a href="https://www.hawki.info/" target="_blank" style="color: #2563eb; text-decoration: none;">unsere Dokumentation</a> zu erkunden.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    Willkommen an Bord!<br>
                    <strong>Das {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getOtpTemplateEn(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Confirm your email address</h1>
            </div>

            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hello {{user_name}},
                </p>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    please confirm your email address. Enter the code below to continue:
                </p>

                <div style="text-align: center; margin: 32px 0;">
                    <div style="background: #f8fafc; border: 2px solid #2563eb; border-radius: 12px; padding: 24px; display: inline-block;">
                        <div style="font-size: 36px; font-weight: 700; color: #2563eb; letter-spacing: 8px; font-family: \'Courier New\', monospace;">{{otp}}</div>
                    </div>
                </div>

                <div style="background: #fef3c7; border: 1px solid #d97706; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #92400e;">🔒 Security note</strong><br>
                    <span style="color: #a16207;">• This code expires in {{otp_validity_minutes}} minutes<br>
                    • Never share this code with anyone<br>
                    • {{app_name}} staff will never ask you for this code</span>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    <strong>Did not request this code?</strong><br>
                    If you did not register with {{app_name}}, please ignore this email. Without the code no access is set up.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    Kind regards,<br>
                    <strong>{{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getOtpTemplateDe(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Bestätigen Sie Ihre E-Mail-Adresse</h1>
            </div>

            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hallo {{user_name}},
                </p>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    bitte bestätigen Sie Ihre E-Mail-Adresse. Geben Sie dazu den folgenden Code ein:
                </p>

                <div style="text-align: center; margin: 32px 0;">
                    <div style="background: #f8fafc; border: 2px solid #2563eb; border-radius: 12px; padding: 24px; display: inline-block;">
                        <div style="font-size: 36px; font-weight: 700; color: #2563eb; letter-spacing: 8px; font-family: \'Courier New\', monospace;">{{otp}}</div>
                    </div>
                </div>

                <div style="background: #fef3c7; border: 1px solid #d97706; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #92400e;">🔒 Sicherheitshinweis</strong><br>
                    <span style="color: #a16207;">• Dieser Code läuft in {{otp_validity_minutes}} Minuten ab<br>
                    • Teilen Sie diesen Code niemals mit anderen<br>
                    • {{app_name}}-Mitarbeiter werden niemals nach diesem Code fragen</span>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    <strong>Haben Sie diesen Code nicht angefordert?</strong><br>
                    Wenn Sie sich nicht bei {{app_name}} registriert haben, ignorieren Sie diese E-Mail bitte. Ohne den Code wird kein Zugang eingerichtet.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    Viele Grüße,<br>
                    <strong>{{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getInvitationTemplateEn(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">New Group Chat Invitation</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hello {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    <strong>{{inviter_name}}</strong> has invited you to join a group chat.
                </p>

                <div style="background: #dbeafe; border: 1px solid #2563eb; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #1d4ed8;">Group Chat:</strong> {{room_name}}
                </div>

                <div style="text-align: center; margin: 32px 0;">
                    <a href="{{invitation_url}}" style="background: #2563eb; color: white; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; display: inline-block;">
                        View Invitation
                    </a>
                </div>

                <div style="background: #dbeafe; border: 1px solid #2563eb; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #1d4ed8;">🔔 Action Required</strong><br>
                    <span style="color: #075985;">Log in to your {{app_name}} account to accept this invitation.</span>
                </div>

                <p style="font-size: 16px; color: #64748b;">
                    Best regards,<br>
                    <strong>The {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getInvitationTemplateDe(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Neue Gruppen-Chat Einladung</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hallo {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    <strong>{{inviter_name}}</strong> hat Sie zu einem Gruppen-Chat eingeladen.
                </p>

                <div style="background: #dbeafe; border: 1px solid #2563eb; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #1d4ed8;">Gruppen-Chat:</strong> {{room_name}}
                </div>

                <div style="text-align: center; margin: 32px 0;">
                    <a href="{{invitation_url}}" style="background: #2563eb; color: white; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; display: inline-block;">
                        Einladung ansehen
                    </a>
                </div>

                <div style="background: #dbeafe; border: 1px solid #2563eb; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #1d4ed8;">🔔 Aktion erforderlich</strong><br>
                    <span style="color: #075985;">Melden Sie sich in Ihrem {{app_name}}-Konto an, um diese Einladung anzunehmen.</span>
                </div>

                <p style="font-size: 16px; color: #64748b;">
                    Mit freundlichen Grüßen,<br>
                    <strong>Das {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getNotificationTemplateEn(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">{{notification_title}}</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hello {{user_name}},
                </p>
                
                <div style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    {{notification_message}}
                </div>

                <div style="text-align: center; margin: 32px 0;">
                    <a href="{{action_url}}" style="background: #2563eb; color: white; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; display: inline-block;">
                        {{action_text}}
                    </a>
                </div>

                <p style="font-size: 16px; color: #64748b;">
                    Best regards,<br>
                    <strong>The {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getNotificationTemplateDe(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">{{notification_title}}</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hallo {{user_name}},
                </p>
                
                <div style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    {{notification_message}}
                </div>

                <div style="text-align: center; margin: 32px 0;">
                    <a href="{{action_url}}" style="background: #2563eb; color: white; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; display: inline-block;">
                        {{action_text}}
                    </a>
                </div>

                <p style="font-size: 16px; color: #64748b;">
                    Mit freundlichen Grüßen,<br>
                    <strong>Das {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getApprovalGrantedTemplateEn(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Account Approved! ✅</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hello {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Great news! Your {{app_name}} account has been approved and is now active.
                </p>

                <div style="background: #dcfce7; border: 1px solid #16a34a; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #15803d;">✅ Account Activated</strong><br>
                    <span style="color: #166534;">Your account has been approved by our administrators.</span>
                </div>

                <h3 style="color: #1f2937; margin: 24px 0 16px 0;">Next Steps:</h3>
                
                <ul style="color: #64748b; margin: 16px 0 24px 20px; line-height: 1.7;">
                    <li><strong>Log In:</strong> Sign in to complete your registration process</li>
                    <li><strong>Complete Registration:</strong> Set up your encryption keys and finalize your account setup</li>
                </ul>

                <div style="text-align: center; margin: 32px 0;">
                    <a href="{{app_url}}/login" style="background: #2563eb; color: white; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; display: inline-block;">
                        Log In to Complete Registration
                    </a>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    If you have any questions, feel free to contact our support team at <a href="mailto:{{support_email}}" style="color: #2563eb; text-decoration: none;">{{support_email}}</a>.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    Best regards,<br>
                    <strong>The {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getApprovalGrantedTemplateDe(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Konto freigeschaltet! ✅</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hallo {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Gute Nachrichten! Ihr {{app_name}}-Account wurde freigeschaltet und ist jetzt aktiv.
                </p>

                <div style="background: #dcfce7; border: 1px solid #16a34a; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #15803d;">✅ Account aktiviert</strong><br>
                    <span style="color: #166534;">Ihr Account wurde von unseren Administratoren freigegeben.</span>
                </div>

                <h3 style="color: #1f2937; margin: 24px 0 16px 0;">Nächste Schritte:</h3>
                
                <ul style="color: #64748b; margin: 16px 0 24px 20px; line-height: 1.7;">
                    <li><strong>Einloggen:</strong> Melden Sie sich an, um die Registrierung abzuschließen</li>
                    <li><strong>Registrierung abschließen:</strong> Richten Sie Ihre Verschlüsselungsschlüssel ein und finalisieren Sie Ihre Account-Einrichtung</li>
                </ul>

                <div style="text-align: center; margin: 32px 0;">
                    <a href="{{app_url}}/login" style="background: #2563eb; color: white; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; display: inline-block;">
                        Einloggen und Registrierung abschließen
                    </a>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Bei Fragen wenden Sie sich gerne an unser Support-Team unter <a href="mailto:{{support_email}}" style="color: #2563eb; text-decoration: none;">{{support_email}}</a>.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    Mit freundlichen Grüßen,<br>
                    <strong>Das {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getApprovalPendingTemplateEn(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Account Request Received 📋</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hello {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Thank you for your interest in {{app_name}}! Your account request has been successfully submitted and is currently being reviewed by our team.
                </p>

                <div style="background: #fef3c7; border: 1px solid #d97706; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #92400e;">⏳ Pending Approval</strong><br>
                    <span style="color: #a16207;">Your account is awaiting approval. You will receive a notification email once your account has been activated.</span>
                </div>

                <h3 style="color: #1f2937; margin: 24px 0 16px 0;">What happens next?</h3>
                
                <ul style="color: #64748b; margin: 16px 0 24px 20px; line-height: 1.7;">
                    <li><strong>Review Process:</strong> Our team will review your account request</li>
                    <li><strong>Email Notification:</strong> You\'ll receive an email when your account is activated</li>
                    <li><strong>Access Granted:</strong> Once approved, you can start using all {{app_name}} features</li>
                </ul>

                <div style="background: #dbeafe; border: 1px solid #2563eb; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #1d4ed8;">💡 Please Note:</strong><br>
                    <span style="color: #1e40af;">The approval process typically takes 1-2 business days. If you have any questions, please don\'t hesitate to contact our support team.</span>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    If you have any questions or need assistance, feel free to reach out to our support team at <a href="mailto:{{support_email}}" style="color: #2563eb; text-decoration: none;">{{support_email}}</a> or explore <a href="https://www.hawki.info/" target="_blank" style="color: #2563eb; text-decoration: none;">our documentation</a>.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    We look forward to welcoming you soon!<br>
                    <strong>The {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getApprovalPendingTemplateDe(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Account-Antrag erhalten 📋</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hallo {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Vielen Dank für Ihr Interesse an {{app_name}}! Ihr Account wurde erfolgreich beantragt und wird derzeit von unserem Team überprüft.
                </p>

                <div style="background: #fef3c7; border: 1px solid #d97706; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #92400e;">⏳ Genehmigung ausstehend</strong><br>
                    <span style="color: #a16207;">Ihr Account wartet auf Freischaltung. Sie erhalten eine Benachrichtigung per E-Mail, sobald Ihr Account aktiviert wurde.</span>
                </div>

                <h3 style="color: #1f2937; margin: 24px 0 16px 0;">Wie geht es weiter?</h3>
                
                <ul style="color: #64748b; margin: 16px 0 24px 20px; line-height: 1.7;">
                    <li><strong>Überprüfung:</strong> Unser Team überprüft Ihren Account-Antrag</li>
                    <li><strong>E-Mail-Benachrichtigung:</strong> Sie erhalten eine E-Mail, sobald Ihr Account freigeschaltet wurde</li>
                    <li><strong>Zugang gewährt:</strong> Nach der Freischaltung können Sie alle {{app_name}}-Funktionen nutzen</li>
                </ul>

                <div style="background: #dbeafe; border: 1px solid #2563eb; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #1d4ed8;">💡 Bitte beachten:</strong><br>
                    <span style="color: #1e40af;">Der Freischaltungsprozess dauert in der Regel 1-2 Werktage. Bei Fragen wenden Sie sich gerne an unser Support-Team.</span>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Wenn Sie Fragen haben oder Unterstützung benötigen, zögern Sie nicht, unser Support-Team unter <a href="mailto:{{support_email}}" style="color: #2563eb; text-decoration: none;">{{support_email}}</a> zu kontaktieren oder <a href="https://www.hawki.info/" target="_blank" style="color: #2563eb; text-decoration: none;">unsere Dokumentation</a> zu erkunden.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    Wir freuen uns darauf, Sie bald willkommen zu heißen!<br>
                    <strong>Das {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getApprovalRevokedTemplateEn(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Account Access Revoked</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hello {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    We are writing to inform you that your access to {{app_name}} has been revoked by an administrator.
                </p>

                <div style="background: #fee2e2; border: 1px solid #dc2626; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #991b1b;">⛔ Access Revoked</strong><br>
                    <span style="color: #b91c1c;">You will no longer be able to access your {{app_name}} account.</span>
                </div>

                <h3 style="color: #1f2937; margin: 24px 0 16px 0;">What does this mean?</h3>
                
                <ul style="color: #64748b; margin: 16px 0 24px 20px; line-height: 1.7;">
                    <li><strong>Account Disabled:</strong> Your account has been deactivated</li>
                    <li><strong>No Access:</strong> You cannot log in or use {{app_name}} features</li>
                    <li><strong>Data Preserved:</strong> Your data remains stored according to our retention policy</li>
                </ul>

                <div style="background: #dbeafe; border: 1px solid #2563eb; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #1d4ed8;">💡 Need Help?</strong><br>
                    <span style="color: #1e40af;">If you believe this is a mistake or have questions, please contact our support team immediately.</span>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    For assistance or to discuss reactivation, please contact our support team at <a href="mailto:{{support_email}}" style="color: #2563eb; text-decoration: none;">{{support_email}}</a>.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    Best regards,<br>
                    <strong>The {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }

    private function getApprovalRevokedTemplateDe(): string
    {
        return '
        <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
            <div style="padding: 32px 32px 16px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #000000;">Zugang widerrufen</h1>
            </div>
            
            <div style="padding: 32px; background: #ffffff;">
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Hallo {{user_name}},
                </p>
                
                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Wir informieren Sie hiermit, dass Ihr Zugang zu {{app_name}} von einem Administrator widerrufen wurde.
                </p>

                <div style="background: #fee2e2; border: 1px solid #dc2626; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #991b1b;">⛔ Zugang widerrufen</strong><br>
                    <span style="color: #b91c1c;">Sie können nicht mehr auf Ihren {{app_name}}-Account zugreifen.</span>
                </div>

                <h3 style="color: #1f2937; margin: 24px 0 16px 0;">Was bedeutet das?</h3>
                
                <ul style="color: #64748b; margin: 16px 0 24px 20px; line-height: 1.7;">
                    <li><strong>Account deaktiviert:</strong> Ihr Account wurde deaktiviert</li>
                    <li><strong>Kein Zugriff:</strong> Sie können sich nicht anmelden oder {{app_name}}-Funktionen nutzen</li>
                    <li><strong>Daten gesichert:</strong> Ihre Daten bleiben gemäß unserer Aufbewahrungsrichtlinie gespeichert</li>
                </ul>

                <div style="background: #dbeafe; border: 1px solid #2563eb; border-radius: 8px; padding: 20px; margin: 24px 0;">
                    <strong style="color: #1d4ed8;">💡 Hilfe benötigt?</strong><br>
                    <span style="color: #1e40af;">Wenn Sie glauben, dass dies ein Fehler ist oder Fragen haben, kontaktieren Sie bitte umgehend unser Support-Team.</span>
                </div>

                <p style="font-size: 16px; color: #64748b; margin-bottom: 24px;">
                    Für Unterstützung oder um eine Reaktivierung zu besprechen, kontaktieren Sie bitte unser Support-Team unter <a href="mailto:{{support_email}}" style="color: #2563eb; text-decoration: none;">{{support_email}}</a>.
                </p>

                <p style="font-size: 16px; color: #64748b;">
                    Mit freundlichen Grüßen,<br>
                    <strong>Das {{app_name}} Team</strong>
                </p>
            </div>
        </div>';
    }
}
