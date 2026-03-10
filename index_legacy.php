<?php ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Regis Marie College | Library Management System</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="landing-page">
<div class="site-shell">
    <div class="topbar landing-topbar">
        <div class="brand-block">
            <img class="brand-logo" src="assets/images/RMLOGO.jfif" alt="Regis Marie College logo">
            <div class="brand-copy">
                <h1>Regis Marie College</h1>
                <p>Library Management System</p>
            </div>
        </div>
        <div class="topbar-nav">
            <div class="landing-nav-links">
                <a class="is-active" href="index.php">Home</a>
                <a href="#services">Services</a>
                <a href="#access">Access</a>
            </div>
            <a class="nav-cta" href="loginpage.php">Login</a>
        </div>
    </div>

    <section class="landing-hero">
        <div class="landing-hero-grid">
            <div class="landing-hero-copy">
                <p class="landing-kicker">Regis Marie College Library</p>
                <h2 class="landing-hero-title">A modern online library built for faster search, cleaner borrowing, and clearer campus access.</h2>
                <p class="landing-hero-text">Open the library portal, manage borrowed books, check return activity, and reach the library desk through a homepage designed to feel polished, simple, and reliable at first glance.</p>
                <div class="landing-hero-actions">
                    <a class="button" href="loginpage.php">Enter Library Portal</a>
                    <a class="landing-inline-link" href="#services">Explore Services</a>
                </div>
                <p class="landing-hero-note">For students, faculty, administrators, and librarians using one shared library system.</p>
            </div>
            <div class="landing-hero-visual">
                <div class="landing-display-photo">
                    <div class="landing-photo-card">
                        <span class="code-pill">Library Online</span>
                        <strong>Search shelves. Track records. Keep borrowing simple.</strong>
                    </div>
                </div>
                <div class="landing-glow glow-one"></div>
                <div class="landing-glow glow-two"></div>
            </div>
        </div>
    </section>

    <section class="landing-section" id="services">
        <div class="landing-section-head">
            <p class="landing-kicker">Library Services</p>
            <h3>Core library actions stay clear and close to the homepage</h3>
        </div>
        <div class="landing-feature-grid">
            <article class="landing-feature-card">
                <div class="dashboard-icon icon-books" aria-hidden="true"></div>
                <h3>Collection Access</h3>
                <p>Readers can move from the landing page into the library portal with a clearer sense of where books and records live.</p>
            </article>
            <article class="landing-feature-card">
                <div class="dashboard-icon icon-payments" aria-hidden="true"></div>
                <h3>Circulation Records</h3>
                <p>Borrowing, returns, and penalty review stay connected so staff and members can follow one consistent process.</p>
            </article>
            <article class="landing-feature-card">
                <div class="dashboard-icon icon-tools" aria-hidden="true"></div>
                <h3>Staff Management</h3>
                <p>Librarians and admins can manage book activity, confirm returns, and review updates without visual overload.</p>
            </article>
            <article class="landing-feature-card">
                <div class="dashboard-icon icon-feedback" aria-hidden="true"></div>
                <h3>Reader Support</h3>
                <p>Feedback and complaints go directly into the proper review flow for quicker response and better tracking.</p>
            </article>
        </div>
    </section>

    <section class="landing-section" id="access">
        <div class="landing-section-head">
            <p class="landing-kicker">Quick Access</p>
            <h3>Start with the library action you need most</h3>
        </div>
        <div class="landing-access-grid">
            <a class="landing-access-card landing-access-card-primary" href="loginpage.php">
                <span class="code-pill">Portal</span>
                <strong>Open Login</strong>
                <span>Sign in as admin, student, faculty, or librarian through the main library portal.</span>
            </a>
            <a class="landing-access-card landing-access-card-featured" href="feedback.php">
                <span class="code-pill">Feedback</span>
                <strong>Complain</strong>
                <span>Report account issues, incorrect records, or library service concerns to the review queue.</span>
            </a>
            <div class="landing-access-card static">
                <span class="code-pill">Contact</span>
                <strong>Library Desk</strong>
                <span>8671-0199 | 0961-437-6209</span>
                <span>itsupport@regismarie-college.com</span>
            </div>
            <div class="landing-access-card static">
                <span class="code-pill">Location</span>
                <strong>Campus Library</strong>
                <span>14°28'36.0"N 121°00'02.3"E</span>
                <span>Visit the library desk for in-person assistance.</span>
            </div>
        </div>
    </section>

    <section class="landing-footer">
        <div class="landing-footer-overlay">
            <div class="landing-footer-header">
                <div class="landing-footer-copy">
                    <p class="landing-kicker landing-footer-kicker">Campus Access</p>
                    <h3 class="landing-footer-title">Keep the library team and readers connected in one simple digital space.</h3>
                </div>
                <span class="chip">Regis Marie College</span>
            </div>

            <div class="landing-contact-grid">
                <div class="landing-contact-item">
                    <strong>Portal</strong>
                    <span><a href="loginpage.php">Open library login</a></span>
                </div>
                <div class="landing-contact-item">
                    <strong>Contact</strong>
                    <span>8671-0199 | 0961-437-6209</span>
                </div>
                <div class="landing-contact-item">
                    <strong>Email Us</strong>
                    <span>itsupport@regismarie-college.com</span>
                </div>
                <div class="landing-contact-item">
                    <strong>Address</strong>
                    <span>14°28'36.0"N 121°00'02.3"E</span>
                </div>
            </div>
        </div>
    </section>

    <div class="footer-note">Copyright <?php echo date('Y'); ?> Regis Marie College</div>
</div>
</body>
</html>
