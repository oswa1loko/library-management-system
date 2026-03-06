import { useEffect, useRef, useState } from "react";
import {
  BookOpen,
  ClipboardList,
  Users,
  MessageSquare,
  LogIn,
  MapPin,
  Mail,
  Phone,
  Sun,
  Moon,
  Menu,
  X,
  ChevronLeft,
  ChevronRight,
  Gauge,
  ShieldCheck,
  ScanSearch,
  ArrowRight,
} from "lucide-react";

const navLinks = [
  { label: "Home", href: "#top" },
  { label: "Services", href: "#services" },
  { label: "Access", href: "#access" },
  { label: "Contact", href: "#contact" },
];

const coreActions = [
  {
    title: "Collection Access",
    description: "Browse available titles quickly and find what you need in fewer steps.",
    icon: BookOpen,
    href: "/librarymanage/loginpage.php",
    cta: "Open collection",
  },
  {
    title: "Circulation Records",
    description: "Track borrowing history and monitor returns with clear status updates.",
    icon: ClipboardList,
    href: "/librarymanage/loginpage.php",
    cta: "View records",
  },
  {
    title: "Staff Management",
    description: "Support librarian workflows with organized tools for daily operations.",
    icon: Users,
    href: "/librarymanage/loginpage.php",
    cta: "Manage staff",
  },
  {
    title: "Reader Support",
    description: "Keep assistance simple with direct channels for concerns and guidance.",
    icon: MessageSquare,
    href: "/librarymanage/feedback.php",
    cta: "Get support",
  },
];
const quickAccessTiles = [
  {
    title: "Open Login",
    helper: "Sign in as admin, student, faculty, or custodian.",
    icon: LogIn,
    href: "/librarymanage/loginpage.php",
  },
  {
    title: "Complain",
    helper: "Report account or library service concerns.",
    icon: MessageSquare,
    href: "/librarymanage/feedback.php",
  },
  {
    title: "Library Desk",
    helper: "Contact the desk via email for support.",
    icon: Mail,
    href: "mailto:itsupport@regismarie-college.com",
  },
  {
    title: "Campus Library",
    helper: "Open location details in maps.",
    icon: MapPin,
    href: "https://maps.google.com/?q=14%C2%B028%2736.0%22N+121%C2%B000%2702.3%22E",
  },
];

const contacts = [
  { label: "Phone", value: "8671-0199 | 0961-437-6209", icon: Phone },
  { label: "Email", value: "itsupport@regismarie-college.com", icon: Mail },
  { label: "Address", value: "Villanueva Village Basketball Court, Lire Ln, Parañaque, 1709 Metro Manila", icon: MapPin },
];

const featureChips = [
  { label: "Fast search", icon: ScanSearch },
  { label: "Borrow tracking", icon: Gauge },
  { label: "Role-based access", icon: ShieldCheck },
];

const statsMini = [
  { label: "Catalog", value: "3k+ Titles", icon: BookOpen },
  { label: "Loans", value: "Daily Tracking", icon: ClipboardList },
  { label: "Requests", value: "Live Queue", icon: Gauge },
];

const roleShortcuts = [
  { label: "Admin", href: "/librarymanage/loginpage.php?role=admin" },
  { label: "Student", href: "/librarymanage/loginpage.php?role=student" },
  { label: "Faculty", href: "/librarymanage/loginpage.php?role=faculty" },
  { label: "Custodian", href: "/librarymanage/loginpage.php?role=custodian" },
];

const statusStrip = [
  { label: "System Status", value: "Online" },
  { label: "Support Hours", value: "Mon-Sat 8:00 AM-5:00 PM" },
  { label: "Location", value: "Villanueva Village Basketball Court, Lire Ln, Parañaque, 1709 Metro Manila" },
];

// Replace these URLs with your official campus/library photos when available.
const heroSlides = [
  {
    src: "/librarymanage/assets/images/IMG_6927.JPG",
    alt: "Regis Marie College campus library",
  },
  {
    src: "https://images.unsplash.com/photo-1507842217343-583bb7270b66?auto=format&fit=crop&w=1600&q=80",
    alt: "Library shelves",
  },
  {
    src: "https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?auto=format&fit=crop&w=1600&q=80",
    alt: "Library reading area",
  },
  {
    src: "https://images.unsplash.com/photo-1481627834876-b7833e8f5570?auto=format&fit=crop&w=1600&q=80",
    alt: "Books in library corridor",
  },
];

const focusBase =
  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950";

const panelBase =
  "rounded-2xl border border-slate-200/85 bg-white/85 p-6 shadow-[0_14px_30px_-18px_rgba(15,23,42,0.28)] backdrop-blur md:p-8 dark:border-white/14 dark:bg-[linear-gradient(180deg,rgba(12,29,49,0.96),rgba(6,18,31,0.94))] dark:shadow-[0_22px_42px_-20px_rgba(0,0,0,0.5)]";

const cardBase =
  "group rounded-2xl border border-slate-200 bg-white p-5 shadow-md transition-all duration-200 ease-out hover:-translate-y-1 hover:border-cyan-400/60 hover:shadow-[0_16px_30px_-20px_rgba(6,182,212,0.35)] dark:border-white/14 dark:bg-[linear-gradient(180deg,rgba(13,31,52,0.94),rgba(7,20,35,0.92))]";

const softCardBase =
  "rounded-xl border border-slate-200/85 bg-white/80 p-3 shadow-sm transition-all duration-200 ease-out dark:border-white/14 dark:bg-[linear-gradient(180deg,rgba(14,34,56,0.9),rgba(8,22,39,0.86))]";

const THEME_STORAGE_KEY = "librarymanage-theme";

function RevealSection({ id, className = "", delay = 0, children }) {
  const ref = useRef(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const node = ref.current;
    if (!node) return;

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            setVisible(true);
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.14, rootMargin: "0px 0px -8% 0px" },
    );

    observer.observe(node);
    return () => observer.disconnect();
  }, []);

  return (
    <section
      id={id}
      ref={ref}
      style={{ transitionDelay: `${delay}ms` }}
      className={`${className} transition-all duration-700 ease-out ${
        visible ? "translate-y-0 opacity-100" : "translate-y-6 opacity-0"
      }`}
    >
      {children}
    </section>
  );
}

export default function LandingPage() {
  const [theme, setTheme] = useState(() => {
    if (typeof window === "undefined") {
      return "dark";
    }

    const attrTheme = document.documentElement.getAttribute("data-theme");
    const saved = window.localStorage.getItem(THEME_STORAGE_KEY);
    const initial = saved || attrTheme || "dark";
    return initial === "light" ? "light" : "dark";
  });
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [currentSlide, setCurrentSlide] = useState(0);
  const [isSliderPaused, setIsSliderPaused] = useState(false);
  const [activeSection, setActiveSection] = useState("Home");

  useEffect(() => {
    const root = document.documentElement;
    const isDark = theme === "dark";
    root.classList.toggle("dark", isDark);
    root.setAttribute("data-theme", theme);
    root.style.colorScheme = isDark ? "dark" : "light";
    localStorage.setItem(THEME_STORAGE_KEY, theme);
  }, [theme]);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 16);
    onScroll();
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    const sectionMap = [
      { id: "services", label: "Services" },
      { id: "access", label: "Access" },
      { id: "contact", label: "Contact" },
    ];

    const updateActive = () => {
      const scrollPos = window.scrollY + 140;
      let current = "Home";

      for (const section of sectionMap) {
        const node = document.getElementById(section.id);
        if (!node) continue;
        if (node.offsetTop <= scrollPos) {
          current = section.label;
        }
      }

      setActiveSection(current);
    };

    updateActive();
    window.addEventListener("scroll", updateActive);
    return () => window.removeEventListener("scroll", updateActive);
  }, []);

  useEffect(() => {
    if (isSliderPaused) return undefined;
    const timer = window.setInterval(() => {
      setCurrentSlide((v) => (v + 1) % heroSlides.length);
    }, 5000);
    return () => window.clearInterval(timer);
  }, [isSliderPaused]);

  const toggleTheme = () => setTheme((v) => (v === "dark" ? "light" : "dark"));
  const nextSlide = () => setCurrentSlide((v) => (v + 1) % heroSlides.length);
  const prevSlide = () => setCurrentSlide((v) => (v - 1 + heroSlides.length) % heroSlides.length);
  const closeMobileMenu = () => setMenuOpen(false);
  const handleNavClick = (event, href) => {
    if (!href.startsWith("#")) {
      return;
    }

    event.preventDefault();
    const target = document.querySelector(href);
    if (!target) {
      return;
    }

    const headerOffset = 92;
    const targetTop = target.getBoundingClientRect().top + window.scrollY - headerOffset;
    window.scrollTo({ top: Math.max(0, targetTop), behavior: "smooth" });
    setMenuOpen(false);
  };

  return (
    <div
      id="top"
      className="relative min-h-screen scroll-smooth overflow-x-hidden bg-slate-50 text-slate-900 dark:bg-[linear-gradient(160deg,#04101d_0%,#07182a_52%,#0d2848_100%)] dark:text-[#f5f7ff]"
    >
      <div className="pointer-events-none fixed inset-0 -z-10">
        <div className="absolute inset-0 bg-[linear-gradient(to_right,rgba(148,163,184,0.07)_1px,transparent_1px),linear-gradient(to_bottom,rgba(148,163,184,0.07)_1px,transparent_1px)] bg-[size:34px_34px] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.045)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.045)_1px,transparent_1px)]" />
        <div className="absolute -top-20 left-[-8%] h-[22rem] w-[22rem] rounded-full bg-cyan-500/12 blur-3xl dark:bg-[#47b4ff]/12" />
        <div className="absolute right-[-10%] top-[30%] h-[20rem] w-[20rem] rounded-full bg-cyan-400/8 blur-3xl dark:bg-[#8fd3ff]/8" />
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_center,transparent_50%,rgba(15,23,42,0.1)_100%)] dark:bg-[radial-gradient(circle_at_center,transparent_42%,rgba(4,11,20,0.78)_100%)]" />
      </div>

      <header
        className={`sticky top-0 z-50 border-b border-slate-200/85 bg-white/82 backdrop-blur-xl transition-all duration-200 ease-out dark:border-white/18 dark:bg-[linear-gradient(135deg,rgba(7,19,34,0.97),rgba(5,14,26,0.95))] ${
          scrolled ? "py-1" : "py-0"
        }`}
      >
        <nav
          className={`mx-auto flex max-w-7xl items-center justify-between px-4 transition-all duration-200 ease-out sm:px-6 lg:px-8 ${
            scrolled ? "py-2" : "py-4"
          }`}
        >
          <a href="/librarymanage/" className={`flex items-center gap-3 ${focusBase}`}>
            <img
              src="/librarymanage/assets/images/RMLOGO.jfif"
              alt="Regis Marie College logo"
              className="h-10 w-10 rounded-full border border-cyan-400/40 object-cover"
            />
            <div>
              <p className="text-sm font-semibold text-slate-900 dark:text-slate-100">Regis Marie College</p>
              <p className="text-xs text-slate-600 dark:text-slate-400">Library Management System</p>
            </div>
          </a>

          <div className="hidden items-center gap-8 md:flex">
            {navLinks.map((link) => (
              <a
                key={link.label}
                href={link.href}
                onClick={(event) => handleNavClick(event, link.href)}
                className={`text-sm transition-all duration-200 ease-out ${focusBase} ${
                  activeSection === link.label
                    ? "text-cyan-600 dark:text-cyan-300"
                    : "text-slate-700 hover:text-cyan-500 dark:text-slate-300"
                }`}
              >
                {link.label}
              </a>
            ))}
          </div>

          <div className="hidden items-center gap-2 md:flex">
            <a
              href="/librarymanage/loginpage.php"
              className={`inline-flex items-center gap-2 rounded-xl bg-cyan-500 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-cyan-500/25 transition-all duration-200 ease-out hover:-translate-y-1 hover:bg-cyan-400 ${focusBase}`}
            >
              <LogIn className="h-4 w-4" />
              Login
            </a>
            <button
              onClick={toggleTheme}
              className={`rounded-xl border border-slate-200 bg-white p-2.5 text-slate-700 transition-all duration-200 ease-out hover:-translate-y-1 hover:border-cyan-400 hover:text-cyan-500 dark:border-white/15 dark:bg-slate-900/60 dark:text-slate-200 ${focusBase}`}
              aria-label="Toggle theme"
            >
              {theme === "dark" ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
            </button>
          </div>

          <div className="flex items-center gap-2 md:hidden">
            <button
              onClick={toggleTheme}
              className={`rounded-xl border border-slate-200 bg-white p-2.5 text-slate-700 transition-all duration-200 ease-out dark:border-white/15 dark:bg-slate-900/60 dark:text-slate-200 ${focusBase}`}
              aria-label="Toggle theme"
            >
              {theme === "dark" ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
            </button>
            <button
              onClick={() => setMenuOpen((v) => !v)}
              className={`rounded-xl border border-slate-200 bg-white p-2.5 text-slate-700 transition-all duration-200 ease-out dark:border-white/15 dark:bg-slate-900/60 dark:text-slate-200 ${focusBase}`}
              aria-label="Toggle menu"
            >
              {menuOpen ? <X className="h-4 w-4" /> : <Menu className="h-4 w-4" />}
            </button>
          </div>
        </nav>

        {menuOpen && (
          <div className="border-t border-slate-200/80 bg-white/85 px-4 py-4 backdrop-blur-xl dark:border-white/10 dark:bg-slate-950/80 md:hidden">
            <div className="mx-auto flex max-w-7xl flex-col gap-3">
              {navLinks.map((link) => (
                <a
                  key={link.label}
                  href={link.href}
                  onClick={(event) => handleNavClick(event, link.href)}
                  className={`rounded-xl px-3 py-2 text-sm transition-all duration-200 ease-out ${focusBase} ${
                    activeSection === link.label
                      ? "bg-cyan-400/10 text-cyan-600 dark:text-cyan-300"
                      : "text-slate-700 hover:bg-cyan-400/10 hover:text-cyan-600 dark:text-slate-200 dark:hover:text-cyan-300"
                  }`}
                >
                  {link.label}
                </a>
              ))}
              <a
                href="/librarymanage/loginpage.php"
                onClick={closeMobileMenu}
                className={`mt-1 inline-flex w-fit items-center gap-2 rounded-xl bg-cyan-500 px-4 py-2 text-sm font-medium text-white ${focusBase}`}
              >
                <LogIn className="h-4 w-4" />
                Login
              </a>
            </div>
          </div>
        )}
      </header>

      <main className="space-y-12 py-12 md:space-y-16 md:py-16">
        <RevealSection className="mx-auto grid max-w-7xl items-center gap-10 px-4 sm:px-6 lg:grid-cols-2 lg:gap-14 lg:px-8">
          <div>
            <span className="inline-flex items-center rounded-full border border-cyan-400/35 bg-cyan-400/10 px-3 py-1 text-xs font-medium text-cyan-700 dark:text-cyan-300">
              Library Platform
            </span>
            <h1 className="mt-4 text-4xl font-semibold leading-[1.05] tracking-tight text-slate-900 md:text-5xl lg:text-6xl dark:text-slate-100">
              Regis Marie College Library portal for faster search, cleaner borrowing, and clearer access.
            </h1>
            <p className="mt-5 text-base text-slate-600 md:text-lg dark:text-slate-300/80">
              Access one system for catalog browsing, borrow tracking, return requests, and payment review across admin, student, faculty, and custodian roles.
            </p>

            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <a
                href="/librarymanage/loginpage.php"
                className={`inline-flex w-full items-center justify-center gap-2 rounded-xl bg-cyan-500 px-5 py-3 text-sm font-medium text-white shadow-lg shadow-cyan-500/25 transition-all duration-200 ease-out hover:-translate-y-1 hover:bg-cyan-400 sm:w-auto ${focusBase}`}
              >
                <LogIn className="h-4 w-4" />
                Enter Library Portal
              </a>
              <a
                href="#services"
                className={`inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-300/80 bg-white px-5 py-3 text-sm font-medium text-slate-700 transition-all duration-200 ease-out hover:-translate-y-1 hover:border-cyan-400/40 hover:text-cyan-600 dark:border-white/20 dark:bg-slate-900/30 dark:text-slate-200 dark:hover:text-cyan-300 sm:w-auto ${focusBase}`}
              >
                <BookOpen className="h-4 w-4" />
                Explore Services
              </a>
            </div>

            <div className="mt-4 flex flex-wrap gap-2">
              {roleShortcuts.map((item) => (
                <a
                  key={item.label}
                  href={item.href}
                  className={`inline-flex items-center rounded-full border border-cyan-400/35 bg-cyan-400/10 px-3 py-1 text-xs font-medium text-cyan-700 transition-all duration-200 hover:border-cyan-400/60 hover:bg-cyan-400/15 dark:text-cyan-300 ${focusBase}`}
                >
                  {item.label} Login
                </a>
              ))}
            </div>

            <div className="mt-6 flex flex-wrap gap-2">
              {featureChips.map((chip) => (
                <span
                  key={chip.label}
                  className="inline-flex items-center gap-1.5 rounded-full border border-cyan-400/35 bg-cyan-400/10 px-3 py-1 text-xs text-cyan-700 transition-all duration-200 hover:border-cyan-400/60 hover:bg-cyan-400/15 dark:text-cyan-300"
                >
                  <chip.icon className="h-3.5 w-3.5" />
                  {chip.label}
                </span>
              ))}
            </div>

            <div className="mt-7 grid grid-cols-1 gap-2 sm:grid-cols-3">
              {statsMini.map((item, idx) => (
                <div key={item.label} className={`${softCardBase} ${idx < 2 ? "sm:relative" : ""}`}>
                  {idx < 2 && <span className="pointer-events-none absolute right-0 top-3 hidden h-8 w-px bg-slate-200 dark:bg-white/10 sm:block" />}
                  <div className="flex items-center gap-2">
                    <item.icon className="h-3.5 w-3.5 text-cyan-500" />
                    <p className="text-xs text-slate-500 dark:text-slate-400">{item.label}</p>
                  </div>
                  <p className="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-100">{item.value}</p>
                </div>
              ))}
            </div>
          </div>

          <div className={panelBase}>
            <div
              className="relative overflow-hidden rounded-xl border border-white/20 bg-slate-950/40"
              role="button"
              tabIndex={0}
              onMouseEnter={() => setIsSliderPaused(true)}
              onMouseLeave={() => setIsSliderPaused(false)}
              onFocusCapture={() => setIsSliderPaused(true)}
              onBlurCapture={() => setIsSliderPaused(false)}
              onClick={nextSlide}
              onKeyDown={(event) => {
                if (event.key === "Enter" || event.key === " ") {
                  event.preventDefault();
                  nextSlide();
                }
              }}
              aria-label="Preview next library image"
            >
              <div className="flex items-center gap-1.5 border-b border-white/10 bg-slate-900/70 px-3 py-2">
                {heroSlides.map((slide, index) => (
                  <button
                    key={`top-dot-${slide.src}`}
                    type="button"
                    onClick={(event) => {
                      event.stopPropagation();
                      setCurrentSlide(index);
                    }}
                    className={`h-2 w-2 rounded-full transition-all duration-200 ${
                      index === currentSlide ? "bg-cyan-400" : "bg-white/35 hover:bg-white/60"
                    } ${focusBase}`}
                    aria-label={`Go to slide ${index + 1}`}
                  />
                ))}
              </div>

              <div className="relative h-[340px] w-full sm:h-[420px]">
                <img
                  key={heroSlides[currentSlide].src}
                  src={heroSlides[currentSlide].src}
                  alt={heroSlides[currentSlide].alt}
                  className="h-full w-full object-cover transition-opacity duration-300"
                />
              </div>

              <div className="pointer-events-none absolute inset-x-0 bottom-0 top-8 bg-gradient-to-t from-slate-950/80 via-slate-950/20 to-transparent" />

              <button
                type="button"
                className={`absolute left-3 top-1/2 -translate-y-1/2 rounded-full border border-white/20 bg-slate-950/55 p-2 text-white transition-all duration-200 hover:border-cyan-400/60 hover:bg-slate-900/80 ${focusBase}`}
                onClick={(event) => {
                  event.stopPropagation();
                  prevSlide();
                }}
                aria-label="Previous image"
              >
                <ChevronLeft className="h-4 w-4" />
              </button>
              <button
                type="button"
                className={`absolute right-3 top-1/2 -translate-y-1/2 rounded-full border border-white/20 bg-slate-950/55 p-2 text-white transition-all duration-200 hover:border-cyan-400/60 hover:bg-slate-900/80 ${focusBase}`}
                onClick={(event) => {
                  event.stopPropagation();
                  nextSlide();
                }}
                aria-label="Next image"
              >
                <ChevronRight className="h-4 w-4" />
              </button>

              <div className="absolute inset-x-4 bottom-4 rounded-xl border border-white/15 bg-slate-950/60 p-4 text-white backdrop-blur">
                <p className="text-xs font-medium uppercase tracking-[0.14em] text-cyan-300">Library Online</p>
                <p className="mt-1 text-sm text-slate-100">Search shelves. Track records. Keep borrowing simple.</p>
                <p className="sr-only" aria-live="polite">
                  Showing image {currentSlide + 1} of {heroSlides.length}
                </p>
              </div>
            </div>
          </div>
        </RevealSection>

        <RevealSection delay={20} className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid gap-3 rounded-2xl border border-slate-200/85 bg-white/85 p-4 shadow-sm sm:grid-cols-3 dark:border-white/12 dark:bg-slate-900/45">
            {statusStrip.map((item) => (
              <div key={item.label} className="rounded-xl border border-slate-200/85 bg-white/70 p-3 dark:border-white/12 dark:bg-slate-950/40">
                <p className="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{item.label}</p>
                <p className="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-100">{item.value}</p>
              </div>
            ))}
          </div>
        </RevealSection>

        <RevealSection id="services" delay={40} className="mx-auto max-w-7xl border-t border-slate-200/70 px-4 pt-12 sm:px-6 md:pt-16 lg:px-8 dark:border-white/10">
          <div className="mb-8">
            <h2 className="text-xl font-semibold md:text-2xl">Core Actions</h2>
            <p className="mt-2 max-w-2xl text-sm text-slate-600 dark:text-slate-300/75">
              Essential workflows for managing access, records, staff operations, and reader support in one system.
            </p>
          </div>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {coreActions.map((item) => (
              <a key={item.title} href={item.href} className={`${cardBase} ${focusBase} flex h-full flex-col`}>
                <div className="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-xl border border-cyan-400/35 bg-cyan-400/10 text-cyan-400">
                  <item.icon className="h-5 w-5" />
                </div>
                <h3 className="text-lg font-semibold tracking-tight">{item.title}</h3>
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300/80">{item.description}</p>
                <span className="mt-auto inline-flex items-center gap-1 pt-5 text-sm text-cyan-600 opacity-0 transition-all duration-200 group-hover:opacity-100 dark:text-cyan-300">
                  {item.cta} <ArrowRight className="h-3.5 w-3.5" />
                </span>
              </a>
            ))}
          </div>
        </RevealSection>

        <RevealSection id="access" delay={80} className="mx-auto max-w-7xl border-t border-slate-200/70 px-4 pt-12 sm:px-6 md:pt-16 lg:px-8 dark:border-white/10">
          <div className={panelBase}>
            <div className="mb-6">
              <h2 className="text-xl font-semibold md:text-2xl">Start with the library action you need most</h2>
              <p className="mt-2 text-sm text-slate-600 dark:text-slate-300/75">
                Open common actions quickly from one section.
              </p>
            </div>
            <div className="mt-2 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {quickAccessTiles.map((tile) => (
                <a key={tile.title} href={tile.href} className={`${cardBase} ${focusBase}`}>
                  <div className="mb-3 inline-flex h-9 w-9 items-center justify-center rounded-lg border border-cyan-400/35 bg-cyan-400/10 text-cyan-400">
                    <tile.icon className="h-4.5 w-4.5" />
                  </div>
                  <p className="text-sm font-semibold">{tile.title}</p>
                  <p className="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300/80">{tile.helper}</p>
                </a>
              ))}
            </div>
          </div>
        </RevealSection>

        <RevealSection id="contact" delay={120} className="mx-auto max-w-7xl border-t border-slate-200/70 px-4 pt-12 sm:px-6 md:pt-16 lg:px-8 dark:border-white/10">
          <div className="mb-8">
            <h2 className="text-xl font-semibold md:text-2xl">Keep the library team and readers connected</h2>
            <p className="mt-2 max-w-2xl text-sm text-slate-600 dark:text-slate-300/75">
              Reach the support team quickly through official contact channels and campus location details.
            </p>
          </div>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {contacts.map((item) => (
              <article key={item.label} className={cardBase}>
                <div className="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-xl border border-cyan-400/35 bg-cyan-400/10 text-cyan-400">
                  <item.icon className="h-5 w-5" />
                </div>
                <p className="text-sm text-slate-500 dark:text-slate-400">{item.label}</p>
                <p className="mt-1 break-words text-sm font-medium leading-relaxed">{item.value}</p>
              </article>
            ))}
          </div>
        </RevealSection>
      </main>

      <footer className="mt-14 border-t border-slate-200/80 py-10 dark:border-white/10 md:mt-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="grid gap-8 border-b border-slate-200/80 pb-8 sm:grid-cols-2 lg:grid-cols-4 dark:border-white/10">
            <div>
              <p className="text-sm font-semibold">Regis Marie College</p>
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Library Management System</p>
            </div>
            <div>
              <p className="text-sm font-semibold">Product</p>
              <div className="mt-2 grid gap-1.5 text-xs">
                <a href="/librarymanage/loginpage.php" className="text-slate-500 transition-colors hover:text-cyan-500 dark:text-slate-400">
                  Portal
                </a>
                <a href="#services" className="text-slate-500 transition-colors hover:text-cyan-500 dark:text-slate-400">
                  Services
                </a>
                <a href="#access" className="text-slate-500 transition-colors hover:text-cyan-500 dark:text-slate-400">
                  Access
                </a>
                <a href="#contact" className="text-slate-500 transition-colors hover:text-cyan-500 dark:text-slate-400">
                  Contact
                </a>
              </div>
            </div>
            <div>
              <p className="text-sm font-semibold">Support</p>
              <div className="mt-2 grid gap-1.5 text-xs">
                <a href="mailto:itsupport@regismarie-college.com" className="text-slate-500 transition-colors hover:text-cyan-500 dark:text-slate-400">
                  Contact
                </a>
                <a href="/librarymanage/feedback.php" className="text-slate-500 transition-colors hover:text-cyan-500 dark:text-slate-400">
                  Complaint Form
                </a>
              </div>
            </div>
            <div>
              <p className="text-sm font-semibold">Legal</p>
              <div className="mt-2 grid gap-1.5 text-xs">
                <a href="/librarymanage/loginpage.php" className="text-slate-500 transition-colors hover:text-cyan-500 dark:text-slate-400">
                  Portal Access
                </a>
                <a href="#top" className="text-cyan-600 transition-colors hover:text-cyan-500 dark:text-cyan-300">
                  Back to top
                </a>
              </div>
            </div>
          </div>

          <div className="pt-5">
            <p className="text-xs text-slate-500 dark:text-slate-400">
              © {new Date().getFullYear()} Regis Marie College. All rights reserved.
            </p>
          </div>
        </div>
      </footer>
    </div>
  );
}

