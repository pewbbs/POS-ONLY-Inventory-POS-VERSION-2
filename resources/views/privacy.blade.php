@php
    $companySignedIn = auth('web')->check();
    $workspaceUrl = route('workspace', ['section' => 'overview'], false);
    $developerPortalUrl = route('developer', ['section' => 'overview'], false);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.public-metadata', ['seo' => $seo ?? [], 'seoTitle' => 'Privacy Policy | POSONLY', 'seoDescription' => 'Read how POSONLY handles account and business data, Google sign-in, subscription payments, cookies, security, and privacy requests.', 'seoHome' => false])
    <meta name="referrer" content="no-referrer">
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/guide.css') }}?v={{ filemtime(public_path('css/guide.css')) }}">
</head>
<body>
    <a class="skip-link" href="#privacy-content">Skip to the privacy policy</a>
    <header class="guide-header">
        <a class="guide-brand" href="{{ route('home') }}" aria-label="POSONLY home">POSONLY</a>
        <nav aria-label="Privacy policy actions">
            <a class="workspace-link" href="{{ $companySignedIn ? $workspaceUrl : route('home', [], false) }}">{{ $companySignedIn ? 'Back to workspace' : 'Back to home' }}</a>
        </nav>
    </header>

    <main id="privacy-content" tabindex="-1">
        <section class="guide-hero" aria-labelledby="privacy-title">
            <h1 id="privacy-title">Privacy policy</h1>
            <div class="guide-intro">
                <p>How we handle your information when you use POSONLY.<br>Effective October 11, 2026.</p>
            </div>
        </section>

        <div class="guide-layout">
            <aside class="guide-sidebar">
                <div class="sidebar-inner">
                    <p class="small-label">Contents</p>
                    <nav class="guide-contents" aria-label="Privacy policy contents">
                        <a href="#operator">Who we are</a>
                        <a href="#information">Information we use</a>
                        <a href="#google">Google sign-in</a>
                        <a href="#sharing">Access and providers</a>
                        <a href="#cookies">Cookies and analytics</a>
                        <a href="#security">Security</a>
                        <a href="#retention">Retention</a>
                        <a href="#choices">Choices and requests</a>
                        <a href="#changes">Policy changes</a>
                    </nav>
                </div>
            </aside>

            <div class="guide-articles">
                <article id="operator" class="guide-section">
                    <h2>Who we are</h2>
                    <div class="detail-content">
                        <p>POSONLY is operated by <strong>Pablito Torres, trading as POSONLY</strong>. Contact us at <a href="mailto:pewbbs@gmail.com">pewbbs@gmail.com</a> about privacy or your information.</p>
                        <p>POSONLY helps businesses manage inventory, sales, receipts, teams, reports, and subscription records. Business administrators choose what information to enter and which staff can access their workspace. If a business entered your customer or employee information, you can also contact that business about its use of your data.</p>
                    </div>
                </article>

                <article id="information" class="guide-section">
                    <h2>Information we use</h2>
                    <div class="detail-content">
                        <ul>
                            <li><strong>Accounts:</strong> Names, email addresses, company names, team roles, access status, account verification details, and password hashes where password access is used.</li>
                            <li><strong>Business records:</strong> Products and variants, stock, purchase orders, suppliers, sales and returns, customer names and optional email addresses and phone numbers, cash-session records, and staff and location references.</li>
                            <li><strong>Settings and billing:</strong> Receipt logos, company addresses and phone numbers, tax and receipt preferences, subscription plans, trials, renewal dates, usage limits, invoices, payment provider and mode, checkout and payment references, payment status, and confirmation timestamps.</li>
                            <li><strong>Operation and security:</strong> Session information, IP addresses and browser user agents in session records, timestamps, activity and administration history, error logs, and system-health records.</li>
                        </ul>
                        <p>We use these details to provide the features you choose, keep company records together, manage permissions and subscription access, send requested password-recovery emails, and investigate problems or misuse.</p>
                        <p>The POS records payment methods and amounts. Its current checkout form does not collect card numbers, security codes, or bank login credentials. Do not put these details in free-text fields.</p>
                    </div>
                </article>

                <article id="google" class="guide-section">
                    <h2>Google sign-in</h2>
                    <div class="detail-content">
                        <p>Google sign-in is optional. Email-and-password registration and sign-in remain available. When you choose Google, we request basic identity, email, and profile permissions to verify your account.</p>
                        <p>Google supplies an account identifier, your email address and verification status, and your display name when available. We check security information in its signed response to verify the sign-in. The profile permission may include a picture; POSONLY does not extract, display, or store your Google profile picture.</p>
                        <p>We use your Google identity to register your company account, sign you in, or connect Google to the company account you are already using. You review and submit your company details before a new workspace is created. Matching email addresses alone do not link an existing account.</p>
                        <p>We save the provider name, a hash of the Google account identifier, verified email, linked POSONLY user, and connection timestamps. Your POSONLY account also stores its name and email. Temporary sign-in and pending-registration details are held in the protected application session.</p>
                        <p>We do not persist Google access, refresh, or ID tokens. We request no offline access and no access to Gmail messages, Google Drive files, contacts, or calendars. We do not send inventory or sales records to Google for sign-in.</p>
                        <p>Google identity information is used for these account features, not sold or used for advertising. It is handled by our application and hosting infrastructure as described below. Google operates its own sign-in service under <a href="https://policies.google.com/privacy" rel="noreferrer">Google’s privacy policy</a>.</p>
                    </div>
                </article>

                <article id="sharing" class="guide-section">
                    <h2>Access and providers</h2>
                    <div class="detail-content">
                        <p>Your company’s authorized staff can access information allowed by their role. Administrators manage team access, Settings, billing, and supported exports. Customer contact information can be available to staff using that company’s POS.</p>
                        <p>Authorized platform owners manage companies, subscriptions, invoices, and website content. Server and database operators may need technical access to maintain the service or investigate an issue.</p>
                        <p><strong>Hostinger</strong> hosts POSONLY’s application, database, and stored files and processes network requests. Hostinger’s email infrastructure handles outbound password-recovery emails, including destination addresses and message contents.</p>
                        <p>If you choose Google, your browser visits Google’s service and POSONLY exchanges the authorization code with Google to verify your identity. Printed receipts, saved PDFs, and downloaded reports are controlled by the business and the people who create or receive those copies.</p>
                        <p>When you choose enabled <strong>PayMongo hosted checkout</strong> for a subscription invoice, POSONLY sends PayMongo the plan description, invoice amount in Philippine pesos, a payment reference, and checkout return URLs. Your browser opens PayMongo’s payment page. We retain checkout and payment identifiers, payment mode, status, and confirmation times to verify the invoice and manage subscription access.</p>
                        <p>You enter hosted-checkout payment details with PayMongo, rather than in POSONLY’s forms. POSONLY does not collect or store your card number or security code. PayMongo processes information under <a href="https://www.paymongo.com/en/privacy" rel="noreferrer">PayMongo’s privacy policy</a>. Live mode collects real payments; test and local simulated records are labeled separately.</p>
                        <p>External commerce synchronization is not connected. Simulated channel orders and local simulated invoice payments contact no payment provider.</p>
                    </div>
                </article>

                <article id="cookies" class="guide-section">
                    <h2>Cookies and analytics</h2>
                    <div class="detail-content">
                        <p>We use first-party session cookies and request-security mechanisms to keep you signed in and protect forms. Company and developer sign-ins use separate sessions. Blocking these cookies can prevent sign-in or form submission.</p>
                        <p>The website keeps its A/B presentation choice in browser session storage. Our website event records contain landing-page view, demo-open, pricing-view, or trial-click labels, the presentation variant, and timestamps. We use them to understand the website’s main actions.</p>
                        <p>These event records do not contain names, emails, IP addresses, or customer identifiers. Session records and hosting logs are separate and may contain network and browser information. The website does not use a Google Analytics or advertising tracking SDK. Google may use its own cookies on its sign-in pages.</p>
                    </div>
                </article>

                <article id="security" class="guide-section">
                    <h2>Security</h2>
                    <div class="detail-content">
                        <p>The production service uses HTTPS, password hashing, secure session cookies, encrypted session payloads, protected server configuration, and company and role checks. Receipt logos are stored privately and served through an authenticated company route. Google sign-in responses are verified, and sensitive account routes have request protections and rate limits.</p>
                        <p>Business data is stored in the application database and file storage. These controls reduce risk; they do not guarantee absolute security or mean that every database field and backup is individually encrypted.</p>
                    </div>
                </article>

                <article id="retention" class="guide-section">
                    <h2>Retention</h2>
                    <div class="detail-content">
                        <p>The current service has no general automatic deletion period for company accounts, Google connections, customer and business records, sales, invoices, activity history, or website events. Signing out, pausing an account, canceling renewal, or removing Google authorization does not delete these records.</p>
                        <p>The application’s scheduled maintenance removes handled monitoring events older than 30 days and failed-job records after seven days. Outstanding pending queue diagnostics remain. Daily application logs rotate with a default limit of 14 files. These limits do not set retention periods for business records, hosting logs, backups, or downloaded copies.</p>
                        <p>Contact <a href="mailto:pewbbs@gmail.com">pewbbs@gmail.com</a> to ask about a particular record or request deletion. We will need to verify the request and its relationship to the relevant company before acting, and explain any records that need to be retained.</p>
                    </div>
                </article>

                <article id="choices" class="guide-section">
                    <h2>Your choices and privacy requests</h2>
                    <div class="detail-content">
                        <p>Open <strong>Sign-in accounts</strong> to review your company account’s Google connection. Connecting Google requires your signed-in company account and preserves its company and role. It does not grant developer access.</p>
                        <p>You can review or remove Google authorization in <a href="https://myaccount.google.com/connections" rel="noreferrer">Google Account connections</a>. Doing this does not delete your saved POSONLY connection or company records, and it does not end an existing POSONLY session.</p>
                        <p>Company administrators can edit supported Settings fields, replace or remove receipt logos, manage team names, roles and active access, and download supported sales, financial and invoice CSV reports. Those reports are not a complete personal-data export. There is currently no self-service account-deletion or Google-disconnection button.</p>
                        <p>For access, correction, another copy, deletion, or removal of a Google connection, email <a href="mailto:pewbbs@gmail.com">pewbbs@gmail.com</a>. We may need to verify your identity and the business administrator’s authority. If you use Google without a password, arrange another way to sign in before requesting removal of the connection. Eligible accounts can use password recovery.</p>
                    </div>
                </article>

                <article id="changes" class="guide-section">
                    <h2>Policy changes</h2>
                    <div class="detail-content">
                        <p>We will publish updates here with their effective date when our practices change. Changes to Google data access or use will be disclosed before that processing begins, with new permission requested when needed.</p>
                        <p>Questions about this policy? Contact <a href="mailto:pewbbs@gmail.com">pewbbs@gmail.com</a>.</p>
                    </div>
                </article>
            </div>
        </div>
    </main>

    <footer class="guide-footer">
        <a class="guide-brand" href="{{ route('home') }}">POSONLY</a>
        <p>Your business. In sync.</p>
        @if($companySignedIn)<p><a href="{{ $workspaceUrl }}">Back to workspace</a></p>@endif
        <p><a href="{{ $developerPortalUrl }}">Developer portal</a></p>
        <p>@include('partials.public-social-links', ['officialProfiles' => (array) config('seo.official_profiles', [])])</p>
        <span>© {{ now()->year }} POSONLY</span>
    </footer>
</body>
</html>
