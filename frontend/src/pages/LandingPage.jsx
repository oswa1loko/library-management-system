import { useEffect, useRef, useState } from "react";
import {
  ArrowRight,
  BookOpen,
  BriefcaseBusiness,
  Building2,
  CalendarClock,
  GraduationCap,
  Library,
  LogIn,
  Mail,
  MapPin,
  Menu,
  Moon,
  Phone,
  Quote,
  ShieldCheck,
  Sparkles,
  Sun,
  Users,
  X,
} from "lucide-react";

const navLinks = [
  { label: "Home", href: "#top" },
  { label: "About", href: "#about" },
  { label: "Library", href: "#library" },
  { label: "Contact", href: "#contact" },
];

const schoolHighlights = [
  {
    title: "Character-centered learning",
    description: "A school environment shaped to help students grow in discipline, values, and academic confidence.",
    icon: ShieldCheck,
  },
  {
    title: "Accessible quality education",
    description: "Public-facing school information highlights a practical, student-focused approach to learning in Parañaque.",
    icon: GraduationCap,
  },
  {
    title: "Library-supported study flow",
    description: "The portal connects catalog access, borrowing, and return tracking in one organized experience.",
    icon: Library,
  },
];

const programCards = [
  {
    title: "Basic Education",
    description: "Introduces students and visitors to a school-focused academic environment.",
    icon: Building2,
  },
  {
    title: "College Readiness",
    description: "Supports a more guided and organized path toward academic readiness.",
    icon: BriefcaseBusiness,
  },
  {
    title: "Student Services",
    description: "The library portal supports daily campus needs through clearer records and easier access.",
    icon: Users,
  },
];

const portalFeatures = [
  {
    label: "Catalog access",
    value: "Search titles and availability with fewer clicks.",
    icon: BookOpen,
  },
  {
    label: "Borrow monitoring",
    value: "Track requests, due dates, and returns in one place.",
    icon: CalendarClock,
  },
  {
    label: "Multi-role portal",
    value: "Built for admin, faculty, students, and librarians.",
    icon: Users,
  },
];

const contacts = [
  { label: "Phone", value: "8671-0199 | 0961-437-6209", href: "tel:+6386710199", icon: Phone },
  { label: "Email", value: "itsupport@regismarie-college.com", href: "mailto:itsupport@regismarie-college.com", icon: Mail },
  {
    label: "Address",
    value: "Villanueva Village Basketball Court, Lire Ln, Parañaque, 1709 Metro Manila",
    icon: MapPin,
  },
];

const quickLinks = [
  { label: "Open Library Portal", href: "/loginpage.php" },
  { label: "Visit Official School Page", href: "https://www.regismariecollege.com/" },
  { label: "Go to Contact Details", href: "#contact" },
  { label: "Send Feedback", href: "/feedback.php" },
];

const quickAccessImage =
  "/assets/images/MODERN COVER WEBSITE (2).png";

const campusFacts = [
  { label: "Campus", value: "Parañaque City" },
  { label: "Identity", value: "Home of Educators" },
  { label: "Support Hours", value: "Mon-Sat 8:00 AM-5:00 PM" },
];

const schoolStory = [
  "Regis Marie College presents itself as a learning community focused on developing students through academic excellence, character formation, and practical preparation.",
  "This library portal extends that mission online by giving readers a clearer way to discover books, manage requests, and stay connected with campus support.",
  "By bringing school information, library access, and support details together in one homepage, the system creates a more welcoming first impression for visitors and a more organized starting point for campus users.",
  "It also helps present the library as an active part of campus life by making important services, records, and school-connected details easier to reach from the moment a user arrives on the page.",
];

const heroImages = [
  {
    src: "/assets/images/rmc-wildcats.png",
    alt: "RMC Wildcats graphic",
  },
  {
    src: "/assets/images/pikyur.jpg",
    alt: "Regis Marie College image",
  },
];

const builderProfiles = [
  {
    name: "Stromiles Vidal",
    role: "Project Manager, Full Stack Developer, UI/UX Designer, Documentation",
    badge: "Lead Development",
    note: "Led the project direction and contributed to full-stack development, interface planning, and project documentation for the platform.",
    src: "/assets/images/IMG_5307.jpeg",
    position: "center 40%",
  },
  {
    name: "Joshua Pasaporte",
    role: "System Analyst, Full Stack Developer, UI/UX Designer, Documentation",
    badge: "System Analysis",
    note: "Contributed to system analysis, feature implementation, interface design, and technical documentation to support the overall development process.",
    src: "/assets/images/IMG_5309.jpeg",
    position: "center 18%",
  },
  {
    name: "Kyrus Tan",
    role: "Documentation",
    badge: "Documentation",
    note: "Supported the project through clear and organized documentation for presentation, reference, and system understanding.",
    src: "/assets/images/IMG_5308.jpeg",
    position: "center 46%",
  },
];

const focusBase =
  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400 focus-visible:ring-offset-2 focus-visible:ring-offset-[#081119]";
const themeStorageKey = "librarymanage-theme";

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
      { threshold: 0.16, rootMargin: "0px 0px -10% 0px" },
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
        visible ? "translate-y-0 opacity-100" : "translate-y-8 opacity-0"
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

    try {
      const storedTheme = window.localStorage.getItem(themeStorageKey);
      return storedTheme === "light" || storedTheme === "dark" ? storedTheme : "dark";
    } catch {
      return "dark";
    }
  });
  const [menuOpen, setMenuOpen] = useState(false);
  const [menuClosing, setMenuClosing] = useState(false);
  const [activeSection, setActiveSection] = useState("Home");
  const [currentImage, setCurrentImage] = useState(0);

  useEffect(() => {
    document.documentElement.classList.toggle("dark", theme === "dark");
    document.documentElement.setAttribute("data-theme", theme);
    document.documentElement.style.colorScheme = theme;

    try {
      window.localStorage.setItem(themeStorageKey, theme);
    } catch {
      // Ignore storage failures and keep the current session theme only.
    }
  }, [theme]);

  useEffect(() => {
    const timer = window.setInterval(() => {
      setCurrentImage((value) => (value + 1) % heroImages.length);
    }, 4200);

    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    const sections = [
      { id: "about", label: "About" },
      { id: "library", label: "Library" },
      { id: "contact", label: "Contact" },
    ];

    const onScroll = () => {
      const marker = window.scrollY + 160;
      let current = "Home";

      sections.forEach((section) => {
        const node = document.getElementById(section.id);
        if (node && node.offsetTop <= marker) {
          current = section.label;
        }
      });

      setActiveSection(current);
    };

    onScroll();
    window.addEventListener("scroll", onScroll);
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const toggleTheme = () => setTheme((value) => (value === "dark" ? "light" : "dark"));
  const isLightTheme = theme === "light";

  const handleNavClick = (event, href) => {
    if (!href.startsWith("#")) return;

    event.preventDefault();
    const target = document.querySelector(href);
    if (!target) return;

    const targetTop = target.getBoundingClientRect().top + window.scrollY - 84;
    window.scrollTo({ top: Math.max(0, targetTop), behavior: "smooth" });
    setMenuClosing(true);
  };

  const handleMenuToggle = () => {
    if (menuOpen) {
      setMenuClosing(true);
    } else {
      setMenuOpen(true);
      setMenuClosing(false);
    }
  };

  useEffect(() => {
    if (menuClosing) {
      const timer = setTimeout(() => {
        setMenuOpen(false);
        setMenuClosing(false);
      }, 280);
      return () => clearTimeout(timer);
    }
  }, [menuClosing]);

  return (
    <div
      id="top"
      className="relative min-h-screen overflow-x-hidden bg-[#071321] text-[#e9f1fb] dark:bg-[#071321] dark:text-[#f8f6f1]"
    >
      <div className="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div className="absolute inset-0 bg-[linear-gradient(180deg,#071321_0%,#0a1a2b_52%,#0d2136_100%)] sm:bg-[linear-gradient(rgba(7,19,33,0.86),rgba(11,27,45,0.94)),url('/assets/images/awitmo.jfif')] sm:bg-cover sm:bg-center sm:bg-fixed sm:bg-no-repeat" />
        <div className="absolute inset-0 bg-[url('/assets/images/awitmo.jfif')] bg-cover bg-[center_top] bg-no-repeat opacity-30 sm:hidden" />
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(122,200,255,0.06),rgba(7,19,33,0.76)),linear-gradient(180deg,rgba(7,19,33,0.18),rgba(11,27,45,0.66))] sm:bg-[radial-gradient(circle_at_center,rgba(7,19,33,0.16),rgba(7,19,33,0.56)),radial-gradient(circle_at_top_left,rgba(122,200,255,0.12),transparent_30%),radial-gradient(circle_at_right,rgba(185,231,255,0.08),transparent_18%),linear-gradient(180deg,rgba(7,19,33,0.94),rgba(11,27,45,0.96))]" />
      </div>

      <header className="fixed top-0 right-0 left-0 z-[120] border-b border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(11,26,45,0.97),rgba(7,18,32,0.96))] shadow-[0_10px_24px_rgba(0,0,0,0.18)] backdrop-blur-[14px] dark:border-white/10 md:sticky md:z-50 md:bg-[#081422]/88 md:shadow-none md:backdrop-blur-xl">
        <nav className="mx-auto flex w-full max-w-[1380px] items-center justify-between gap-2.5 px-0 py-3.5 md:gap-3 md:py-4">
          <a href="/" className={`group flex min-w-0 flex-1 flex-nowrap items-center gap-3.5 md:flex-none md:gap-4 ${focusBase}`}>
            <img
              src="/assets/images/RMLOGO.jfif"
              alt="Regis Marie College logo"
              className="h-11 w-11 rounded-full border border-[rgba(143,211,255,0.24)] object-cover shadow-[0_10px_24px_rgba(8,24,44,0.26)] transition-transform duration-300 group-hover:scale-[1.03] md:h-[54px] md:w-[54px] md:border-cyan-400/40 md:shadow-[0_14px_28px_rgba(34,83,126,0.24)]"
            />
            <div className="flex min-w-0 shrink flex-col justify-center gap-0.5 max-md:max-w-[calc(100vw-116px)]">
              <p className="truncate text-[0.64rem] leading-none font-semibold uppercase tracking-[0.18em] text-cyan-300/88 [html[data-theme='light']_&]:text-sky-700 md:text-[0.76rem] md:tracking-[0.28em]">
                RMC
              </p>
              <p className="truncate text-[1rem] leading-[1.08] font-semibold tracking-[-0.01em] text-[#f4f8ff] md:text-[1.1rem]">
                Regis Marie College Library
              </p>
              <p className="truncate text-[0.72rem] leading-[1.1] text-slate-400 md:text-[0.78rem]">
                Learning resources and campus access
              </p>
            </div>
          </a>

          <div className="hidden items-center gap-2 rounded-full border border-white/8 bg-white/[0.03] p-1.5 shadow-[inset_0_1px_0_rgba(255,255,255,0.05)] md:flex">
            {navLinks.map((link) => (
              <a
                key={link.label}
                href={link.href}
                onClick={(event) => handleNavClick(event, link.href)}
                aria-current={activeSection === link.label ? "page" : undefined}
                className={`inline-flex min-h-[42px] items-center rounded-full px-4 text-sm font-medium transition-all duration-200 ${focusBase} ${
                  activeSection === link.label
                    ? "bg-cyan-400/16 text-cyan-100 shadow-[0_10px_22px_rgba(34,127,167,0.18)] ring-1 ring-cyan-300/18 dark:bg-cyan-300/12 dark:text-cyan-200"
                    : isLightTheme
                      ? "text-slate-600 hover:bg-cyan-400/10 hover:text-[#0d5f8e] hover:shadow-[0_10px_18px_rgba(14,116,144,0.12)]"
                      : "text-slate-300/92 hover:bg-white/[0.04] hover:text-white dark:text-slate-300 dark:hover:text-white"
                }`}
              >
                {link.label}
              </a>
            ))}
          </div>

          <div className="hidden items-center gap-3 md:flex">
            <a
              href="/loginpage.php"
              className={`inline-flex min-h-[48px] items-center gap-2 rounded-full border border-cyan-200/50 bg-[linear-gradient(135deg,#9be7ff,#32b8f1)] px-6 py-3 text-sm font-bold uppercase tracking-[0.08em] text-[#04111d] shadow-[0_16px_34px_rgba(37,173,235,0.34)] transition-all duration-200 hover:-translate-y-0.5 hover:scale-[1.01] hover:shadow-[0_22px_40px_rgba(37,173,235,0.42)] ${focusBase}`}
            >
              <LogIn className="h-4 w-4" />
              Login
            </a>
            <button
              onClick={toggleTheme}
              className={`inline-flex min-h-[38px] items-center gap-[6px] rounded-full border border-[#75a8db]/20 bg-[linear-gradient(135deg,rgba(255,255,255,0.09),rgba(255,255,255,0.03))] px-[10px] py-[6px] text-[#eef6ff] transition-all duration-200 hover:-translate-y-0.5 hover:border-[rgba(143,211,255,0.22)] ${isLightTheme ? "border-[rgba(47,109,255,0.12)] bg-[linear-gradient(135deg,rgba(255,255,255,0.92),rgba(245,249,255,0.88))] text-[#233853] shadow-[0_8px_18px_rgba(34,63,112,0.05)]" : "bg-[linear-gradient(135deg,rgba(255,255,255,0.09),rgba(255,255,255,0.03))]"} ${focusBase}`}
              aria-label={isLightTheme ? "Switch to dark mode" : "Switch to light mode"}
              aria-pressed={isLightTheme}
            >
              <span className={`inline-flex min-h-7 min-w-[34px] items-center justify-center rounded-full px-1 text-[currentColor] shadow-[0_2px_6px_rgba(0,0,0,0.12)] transition-all duration-300 ${isLightTheme ? "translate-x-[2px] bg-[linear-gradient(135deg,rgba(26,45,71,0.12),rgba(26,45,71,0.05))] shadow-[0_2px_6px_rgba(34,63,112,0.12)]" : "-translate-x-[2px] bg-[linear-gradient(135deg,rgba(255,255,255,0.14),rgba(255,255,255,0.06))]"}`}>
                {isLightTheme ? <Sun className="h-[15px] w-[15px] rotate-[8deg] transition-transform duration-300" /> : <Moon className="h-[15px] w-[15px] -rotate-[7deg] transition-transform duration-300" />}
              </span>
              <span className={`min-w-9 text-center text-xs font-extrabold tracking-[0.01em] transition-transform duration-300 ${isLightTheme ? "translate-x-px" : "-translate-x-px"}`}>{isLightTheme ? "Light" : "Dark"}</span>
            </button>
          </div>

          <div className="flex flex-none items-center gap-1.5 md:hidden">
            <button
              onClick={toggleTheme}
              className={`inline-flex h-10 w-10 appearance-none items-center justify-center rounded-full border border-white/12 bg-white/[0.06] p-0 text-[#eef6ff] shadow-[inset_0_1px_0_rgba(255,255,255,0.04)] transition-colors duration-200 [-webkit-tap-highlight-color:transparent] focus:outline-none ${isLightTheme ? "border-slate-200 bg-white text-slate-700 shadow-[0_8px_18px_rgba(15,23,42,0.08)]" : ""} ${focusBase}`}
              aria-label={isLightTheme ? "Switch to dark mode" : "Switch to light mode"}
              aria-pressed={isLightTheme}
            >
              <span className={`inline-flex items-center justify-center rounded-full transition-colors duration-200 ${isLightTheme ? "h-7 w-7 border border-slate-300 bg-[linear-gradient(180deg,#f3f6fa,#d7e0ea)] text-slate-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.98),0_0_0_1px_rgba(255,255,255,0.9),0_1px_3px_rgba(15,23,42,0.16)]" : "h-7 w-7 bg-white/8 text-[#eef6ff] shadow-[inset_0_1px_0_rgba(255,255,255,0.08)]"}`}>
                {isLightTheme ? <Sun className="h-[16px] w-[16px] rotate-[8deg] transition-transform duration-300" /> : <Moon className="h-[16px] w-[16px] -rotate-[7deg] transition-transform duration-300" />}
              </span>
            </button>
            <button
              onClick={handleMenuToggle}
              className={`inline-flex h-10 w-10 appearance-none items-center justify-center rounded-full border border-white/12 bg-white/[0.06] p-0 text-[#eef6ff] shadow-[inset_0_1px_0_rgba(255,255,255,0.04)] transition-colors duration-200 [-webkit-tap-highlight-color:transparent] focus:outline-none ${isLightTheme ? "border-slate-200 bg-white text-slate-700 shadow-[0_8px_18px_rgba(15,23,42,0.08)]" : ""} ${focusBase}`}
              aria-label="Toggle menu"
            >
              {menuOpen ? <X className="h-[18px] w-[18px]" /> : <Menu className="h-[18px] w-[18px]" />}
            </button>
          </div>
        </nav>

        {menuOpen && (
          <div className={`absolute left-0 right-0 top-full z-[60] border-t border-slate-200/70 bg-white/88 px-3 py-4 backdrop-blur-xl dark:border-white/10 dark:bg-[#09131c]/96 md:hidden ${menuClosing ? "is-closing" : ""}`}>
            <div className="mx-auto flex w-full max-w-[1380px] flex-col gap-3">
              {navLinks.map((link) => (
                <a
                  key={link.label}
                  href={link.href}
                  onClick={(event) => handleNavClick(event, link.href)}
                  aria-current={activeSection === link.label ? "page" : undefined}
                  className={`rounded-2xl px-3 py-2 text-sm ${focusBase} ${
                    activeSection === link.label
                      ? "bg-cyan-400/15 font-semibold text-cyan-700 ring-1 ring-cyan-500/20 dark:text-cyan-300"
                      : "text-slate-700 dark:text-slate-100"
                  }`}
                >
                  {link.label}
                </a>
              ))}
              <a
                href="/loginpage.php"
                className={`inline-flex items-center justify-center gap-2 rounded-2xl bg-[linear-gradient(135deg,#32b8f1,#0ea5e9)] px-4 py-3 text-sm font-bold uppercase tracking-[0.08em] text-white shadow-[0_14px_28px_rgba(37,173,235,0.28)] transition-transform duration-200 hover:-translate-y-0.5 hover:bg-cyan-400 ${focusBase}`}
              >
                <LogIn className="h-4 w-4" />
                Login
              </a>
            </div>
          </div>
        )}
      </header>

      <main className="mx-auto flex w-full max-w-[1380px] flex-col gap-8 px-0 py-[72px] md:gap-16 md:py-14">
        <RevealSection className="grid items-center gap-3 sm:gap-8 lg:min-h-[calc(100vh-7rem)] lg:grid-cols-[minmax(0,1.02fr)_minmax(0,0.98fr)] lg:gap-10">
          <div className="order-1 relative sm:order-none">
            <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-[#75a8db]/25 bg-[#14283f]/88 px-3.5 py-2 text-[0.62rem] font-semibold uppercase tracking-[0.18em] text-cyan-200 shadow-sm sm:mb-4 sm:px-4 sm:text-xs sm:tracking-[0.26em] dark:border-cyan-300/20 dark:bg-white/5 dark:text-cyan-300">
              <Sparkles className="h-3.5 w-3.5" />
              Home of Educators
            </div>
            <h1 className="mt-0 max-w-[12.8ch] text-[1.72rem] font-semibold leading-[1.08] tracking-[-0.035em] text-white sm:max-w-[14ch] sm:text-4xl sm:leading-[1.02] md:max-w-[13ch] md:text-[clamp(2.8rem,5.2vw,5rem)] dark:text-white">
              Regis Marie College Library Management System for simpler access, borrowing, and support.
            </h1>
            <p className="mt-4 max-w-[34ch] text-[0.94rem] leading-[1.78] text-slate-300 sm:mt-5 sm:max-w-[640px] sm:text-base sm:leading-7 md:text-[1rem] md:leading-[1.75] dark:text-slate-300/85">
              A clearer homepage for students, faculty, and staff, bringing library access, school information,
              and support details into one more connected first screen.
            </p>

            <div className="mt-[18px] flex flex-col gap-2 sm:mt-7 sm:gap-3 md:mt-[26px] sm:flex-row">
              <a
                href="/loginpage.php"
                className={`inline-flex w-full items-center justify-center gap-2 rounded-full border border-cyan-200/45 bg-[linear-gradient(135deg,#9be7ff,#32b8f1)] px-7 py-3.5 text-sm font-bold uppercase tracking-[0.08em] text-[#04111d] shadow-[0_18px_38px_rgba(37,173,235,0.34)] transition-all duration-200 hover:-translate-y-1 hover:scale-[1.01] hover:shadow-[0_24px_46px_rgba(37,173,235,0.42)] sm:w-auto ${focusBase}`}
              >
                <LogIn className="h-4 w-4" />
                Access Library Portal
              </a>
              <a
                href="#about"
                onClick={(event) => handleNavClick(event, "#about")}
                className={`inline-flex w-full items-center justify-center gap-2 rounded-full border border-slate-300/80 bg-white/85 px-6 py-3 text-sm font-medium text-slate-700 transition-transform duration-200 hover:-translate-y-1 dark:border-white/15 dark:bg-white/5 dark:text-slate-100 sm:w-auto ${focusBase}`}
              >
                Explore the School
                <ArrowRight className="h-4 w-4" />
              </a>
            </div>

            <div className="hidden sm:mt-9 sm:grid sm:gap-4 sm:grid-cols-3">
              {campusFacts.map((fact) => (
                <div
                  key={fact.label}
                  className="rounded-[1.25rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-4 shadow-[0_20px_45px_-28px_rgba(0,0,0,0.45)] backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:min-h-[124px] sm:rounded-[1.5rem] sm:p-5 dark:border-white/10 dark:bg-white/5"
                >
                  <p className="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">{fact.label}</p>
                  <p className="mt-2 text-sm font-semibold text-white dark:text-white">{fact.value}</p>
                </div>
              ))}
            </div>
          </div>

          <div className="order-2 relative sm:order-none">
            <div className="relative min-w-0 overflow-hidden rounded-[1.35rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.12),rgba(8,18,30,0.68))] p-2 shadow-[0_28px_70px_-30px_rgba(0,0,0,0.55)] backdrop-blur-[10px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] max-md:rounded-[1.75rem] max-md:p-2.5 sm:rounded-[2rem] sm:p-3 dark:border-white/10 dark:bg-white/5">
              <div className="relative h-[230px] overflow-hidden rounded-[1.1rem] border border-blue-500/20 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] backdrop-blur-[8px] max-md:h-[300px] max-md:rounded-[1.35rem] sm:h-[360px] sm:rounded-[1.6rem] md:h-[420px]">
                {heroImages.map((image, index) => (
                  <img
                    key={image.src}
                    src={image.src}
                    alt={image.alt}
                    className={`absolute inset-0 h-full w-full overflow-hidden rounded-[1.1rem] bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] object-cover object-[center_28%] transition-all duration-700 max-md:rounded-[1.35rem] sm:rounded-[1.6rem] sm:object-center ${
                      currentImage === index ? "z-[1] scale-100 opacity-100" : "scale-110 opacity-0"
                    } ${index === 1 ? "object-cover object-center" : ""}`}
                  />
                ))}
                <div className="absolute inset-0 z-[2] rounded-[1.1rem] bg-[linear-gradient(180deg,rgba(13,27,40,0.03),rgba(13,27,40,0.58))] max-md:rounded-[1.35rem] sm:rounded-[1.6rem]" />
                <div className="absolute bottom-3 left-3 right-3 z-[4] text-white sm:bottom-0 sm:left-0 sm:right-0 sm:p-5">
                  <p className="text-[0.58rem] uppercase tracking-[0.2em] text-cyan-300 sm:text-xs sm:tracking-[0.3em]">Regis Marie College</p>
                  <p className="mt-2 text-[1rem] font-semibold leading-[1.4] sm:max-w-sm sm:text-lg sm:leading-tight">Designed to make library access feel like a natural part of campus life.</p>
                </div>
              </div>

              <div className="mt-2.5 grid gap-2.5 sm:mt-[18px] sm:gap-4 sm:grid-cols-3">
                {portalFeatures.map((feature) => (
                  <div
                    key={feature.label}
                    className="h-full rounded-[1rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-3.5 backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[1.3rem] sm:p-4 dark:border-white/10 dark:bg-[#0d1a26]"
                  >
                    <feature.icon className="h-4 w-4 text-cyan-600 dark:text-cyan-300" />
                    <p className="mt-3 text-sm font-semibold text-white dark:text-white">{feature.label}</p>
                    <p className="mt-1 text-xs leading-6 text-slate-300 dark:text-slate-300/80">{feature.value}</p>
                  </div>
                ))}
              </div>
            </div>
          </div>

        </RevealSection>

        <RevealSection id="about" delay={40} className="order-3 grid gap-3 sm:order-none sm:gap-6 lg:grid-cols-[0.95fr_1.05fr] lg:gap-6">
          <div className="min-w-0 rounded-[1.35rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-[18px] text-white shadow-[0_26px_60px_-26px_rgba(6,182,212,0.45)] backdrop-blur-[8px] sm:rounded-[2rem] sm:p-8">
            <div className="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-xs uppercase tracking-[0.22em]">
              <Quote className="h-3.5 w-3.5" />
              About the school
            </div>
            <h2 className="mt-5 max-w-[14ch] text-3xl font-semibold tracking-tight">Regis Marie College builds learning around values, growth, and academic direction.</h2>
            <div className="mt-4 space-y-3 text-sm leading-[1.65] text-white/82 sm:mt-5 sm:space-y-4 sm:leading-7">
              {schoolStory.map((paragraph, index) => (
                <p key={paragraph} className={index >= 2 ? "hidden sm:block" : ""}>
                  {paragraph}
                </p>
              ))}
            </div>
          </div>

          <div className="grid gap-3 sm:gap-4">
            {schoolHighlights.map((item) => (
              <article
                key={item.title}
                className="min-w-0 rounded-[1.2rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-[18px] shadow-[0_18px_45px_-28px_rgba(0,0,0,0.45)] backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[1.7rem] sm:p-6 dark:border-white/10 dark:bg-white/5"
              >
                <div className="inline-flex h-12 w-12 items-center justify-center rounded-2xl border border-[#a7f3ff]/15 bg-[linear-gradient(145deg,rgba(34,211,238,0.2),rgba(96,165,250,0.14))] text-[#c9f7ff] shadow-[0_10px_24px_-14px_rgba(34,211,238,0.55)] dark:text-cyan-300">
                  <item.icon className="h-5 w-5" />
                </div>
                <h3 className="mt-4 text-xl font-semibold text-white dark:text-white">{item.title}</h3>
                  <p className="mt-2 text-sm leading-7 text-slate-300 dark:text-slate-300/80">{item.description}</p>
              </article>
            ))}
          </div>
        </RevealSection>

        <section className="order-4 grid gap-2.5 sm:hidden">
          {campusFacts.map((fact) => (
            <div
              key={`mobile-${fact.label}`}
              className="rounded-[1.25rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-4 shadow-[0_20px_45px_-28px_rgba(0,0,0,0.45)] backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] dark:border-white/10 dark:bg-white/5"
            >
              <p className="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">{fact.label}</p>
              <p className="mt-2 text-sm font-semibold text-white dark:text-white">{fact.value}</p>
            </div>
          ))}
        </section>

        <RevealSection delay={70} className="order-5 grid gap-3 sm:order-none sm:gap-6 lg:grid-cols-[1.05fr_0.95fr]">
          <div className="min-w-0 rounded-[1.35rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-[18px] backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[2rem] sm:p-8 dark:border-white/10 dark:bg-white/5">
            <div className="mb-5 flex items-center justify-between gap-4">
              <div>
                <p className="text-xs uppercase tracking-[0.24em] text-cyan-700 dark:text-cyan-300">School snapshot</p>
                <h2 className="mt-2 max-w-[14ch] text-2xl font-semibold text-white dark:text-white">A homepage that feels more aligned with the school brand.</h2>
              </div>
              <img
                src="/assets/images/RMLOGO.jfif"
                alt="Regis Marie College badge"
                className="h-14 w-14 rounded-full border border-cyan-500/35 object-cover"
              />
            </div>
            <div className="grid gap-3 sm:gap-4 lg:grid-cols-3">
              {programCards.map((card) => (
                <div key={card.title} className="h-full rounded-[1.1rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-4 backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[1.5rem] sm:p-5 dark:border-white/10 dark:bg-[#0d1a26]">
                  <div className="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-[#a7f3ff]/15 bg-[linear-gradient(145deg,rgba(34,211,238,0.2),rgba(96,165,250,0.14))] text-[#c9f7ff] shadow-[0_10px_24px_-14px_rgba(34,211,238,0.55)] dark:text-cyan-300">
                    <card.icon className="h-5 w-5 text-cyan-600 dark:text-cyan-300" />
                  </div>
                  <h3 className="mt-4 text-lg font-semibold text-white dark:text-white">{card.title}</h3>
                  <p className="mt-2 text-sm leading-7 text-slate-300 dark:text-slate-300/80">{card.description}</p>
                </div>
              ))}
            </div>
          </div>

          <div className="relative min-w-0 overflow-hidden rounded-[1.35rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-[18px] text-white shadow-[0_26px_60px_-26px_rgba(15,23,42,0.6)] backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[2rem] sm:p-8 dark:border-white/10">
            <div className="absolute right-0 top-0 h-32 w-32 rounded-full bg-cyan-400/15 blur-3xl" />
            <div className="relative mb-5 min-h-[180px] overflow-hidden rounded-[1.5rem] bg-[#0b1623]">
              <img
                src={quickAccessImage}
                alt="Regis Marie College promotional cover"
                className="h-full w-full object-cover"
              />
              <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(8,18,30,0.22),rgba(8,18,30,0.82))]" />
              <div className="absolute bottom-4 left-4 right-4">
                <p className="text-base font-semibold leading-[1.45] text-white">
                  A stronger first impression for visitors through a more polished school-centered landing page.
                </p>
              </div>
            </div>
            <p className="text-xs uppercase tracking-[0.24em] text-cyan-300">Quick access</p>
            <h2 className="mt-2 max-w-[16ch] text-2xl font-semibold">Start with the portal, school page, and support links visitors need most.</h2>
            <div className="mt-6 grid gap-3">
              {quickLinks.map((link) => (
                <a
                  key={link.label}
                  href={link.href}
                  className={`group flex items-center justify-between rounded-[1.3rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.1),rgba(8,18,30,0.68))] px-4 py-4 text-sm text-white transition-all duration-200 hover:border-cyan-300/40 hover:bg-white/10 dark:text-white dark:hover:bg-white/10 [html[data-theme='light']_&]:border-sky-200/70 [html[data-theme='light']_&]:bg-[linear-gradient(180deg,rgba(240,249,255,0.96),rgba(224,242,254,0.92))] [html[data-theme='light']_&]:text-slate-800 [html[data-theme='light']_&]:shadow-[0_14px_28px_-22px_rgba(14,116,144,0.28)] [html[data-theme='light']_&]:hover:border-sky-300 [html[data-theme='light']_&]:hover:bg-[linear-gradient(180deg,rgba(224,242,254,0.98),rgba(186,230,253,0.96))] ${focusBase}`}
                >
                  <span>{link.label}</span>
                  <ArrowRight className="h-4 w-4 text-cyan-200 transition-transform duration-200 group-hover:translate-x-1 dark:text-cyan-200 [html[data-theme='light']_&]:text-sky-600" />
                </a>
              ))}
            </div>
          </div>
        </RevealSection>

        <RevealSection id="library" delay={100} className="grid gap-3 sm:gap-6 lg:grid-cols-[0.92fr_1.08fr]">
          <div className="min-w-0 overflow-hidden rounded-[1.35rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-2 backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[2rem] sm:p-3 dark:border-white/10 dark:bg-white/5">
            <div className="relative h-full min-h-[240px] overflow-hidden rounded-[1.05rem] sm:min-h-[360px] sm:rounded-[1.5rem]">
              <img
                src="/assets/images/rmc-logo.jpg"
                alt="Regis Marie College logo"
                className="h-full w-full object-cover"
              />
              <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(9,19,28,0.06),rgba(9,19,28,0.78))]" />
              <div className="absolute bottom-0 left-0 right-0 rounded-[1.2rem] border border-[#75a8db]/10 bg-[linear-gradient(180deg,rgba(8,18,30,0.1),rgba(8,18,30,0.76))] p-5 text-white shadow-[inset_0_1px_0_rgba(255,255,255,0.03)] backdrop-blur-[8px] sm:m-6 sm:rounded-[1.4rem] sm:p-6">
                <p className="text-xs uppercase tracking-[0.24em] text-cyan-300 [text-shadow:0_8px_24px_rgba(0,0,0,0.42)] [html[data-theme='light']_&]:text-sky-700 [html[data-theme='light']_&]:[text-shadow:none]">Offered course</p>
                <p className="mt-2 max-w-sm text-lg font-semibold [text-shadow:0_8px_24px_rgba(0,0,0,0.42)] [html[data-theme='light']_&]:text-slate-900 [html[data-theme='light']_&]:[text-shadow:none]">Programs, student growth, and school identity can be introduced in one stronger visual section.</p>
              </div>
            </div>
            <div className="mt-7 grid gap-2.5 px-2 pb-2 sm:mt-8 sm:px-3 sm:pb-3 lg:grid-cols-3">
              {[
                {
                  code: "BSCS",
                  description: "Focuses on computing, programming, and problem-solving skills for digital systems.",
                },
                {
                  code: "BSOA",
                  description: "Supports office administration, organization, communication, and practical business tasks.",
                },
                {
                  code: "BSEd",
                  description: "Prepares future educators through training in teaching, learning strategies, and student guidance.",
                },
                {
                  code: "Certificate of Proficiency",
                  description: "Offers practical training that helps learners develop focused skills for specific career-related tasks.",
                },
                {
                  code: "College Readiness",
                  description: "Helps learners prepare for higher study with stronger academic direction and practical readiness.",
                },
                {
                  code: "Student Services",
                  description: "Connects students to organized support, records, and campus-related academic assistance.",
                },
              ].map((course) => (
                <div
                  key={course.code}
                  className="rounded-[1.1rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.18),rgba(8,18,30,0.72))] p-3.5 backdrop-blur-[8px] [html[data-theme='light']_&]:border-sky-100 [html[data-theme='light']_&]:bg-[linear-gradient(180deg,rgba(248,252,255,0.98),rgba(232,244,255,0.94))] [html[data-theme='light']_&]:shadow-[0_16px_28px_-24px_rgba(14,116,144,0.24)]"
                >
                  <p className="text-[0.82rem] font-bold tracking-[0.04em] text-slate-50 [html[data-theme='light']_&]:text-sky-900">{course.code}</p>
                  <p className="mt-2 text-[0.82rem] leading-6 text-slate-200/90 [html[data-theme='light']_&]:text-slate-600">{course.description}</p>
                </div>
              ))}
            </div>
          </div>

          <div className="min-w-0 rounded-[1.35rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-[18px] shadow-[0_18px_45px_-28px_rgba(0,0,0,0.45)] backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[2rem] sm:p-8 dark:border-white/10 dark:bg-white/5">
            <p className="text-xs uppercase tracking-[0.24em] text-cyan-700 dark:text-cyan-300">Library portal</p>
            <h2 className="mt-2 max-w-[14ch] text-3xl font-semibold tracking-tight text-white dark:text-white">Library tools, school details, and support information in one cleaner experience.</h2>
            <p className="mt-4 text-sm leading-7 text-slate-300 dark:text-slate-300/82">
              The landing page now introduces the library system more clearly, with school-aligned visuals,
              easier access points, and a more organized first impression for visitors.
            </p>

            <div className="mt-6 grid gap-3 sm:gap-4 lg:grid-cols-3">
              {portalFeatures.map((feature) => (
                <div key={feature.label} className="h-full rounded-[1.1rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-4 backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[1.5rem] sm:p-5 dark:border-white/10 dark:bg-[#0d1a26]">
                  <feature.icon className="h-5 w-5 text-cyan-700 dark:text-cyan-300" />
                  <p className="mt-4 text-sm font-semibold text-white dark:text-white">{feature.label}</p>
                  <p className="mt-2 text-sm leading-7 text-slate-300 dark:text-slate-300/80">{feature.value}</p>
                </div>
              ))}
            </div>
          </div>
        </RevealSection>

        <RevealSection delay={118} className="grid gap-3 sm:gap-6 lg:grid-cols-[0.78fr_1.22fr] lg:items-stretch">
          <div className="min-w-0 rounded-[1.35rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-[18px] shadow-[0_18px_45px_-28px_rgba(0,0,0,0.45)] backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] [html[data-theme='light']_&]:border-sky-200/75 [html[data-theme='light']_&]:bg-[linear-gradient(180deg,rgba(250,253,255,0.99),rgba(234,244,255,0.95))] [html[data-theme='light']_&]:shadow-[0_20px_36px_-24px_rgba(14,116,144,0.16)] sm:rounded-[2rem] sm:p-7 lg:flex lg:h-full lg:flex-col dark:border-white/10 dark:bg-white/5">
            <p className="text-xs uppercase tracking-[0.24em] text-cyan-700 dark:text-cyan-300">Development Team</p>
            <h2 className="mt-2 max-w-[14ch] text-[2rem] font-semibold tracking-tight text-white dark:text-white [html[data-theme='light']_&]:text-slate-900 sm:text-3xl">Built by the development team behind the Regis Marie College Library Management System.</h2>
            <p className="mt-4 text-sm leading-7 text-slate-300 dark:text-slate-300/82 [html[data-theme='light']_&]:text-slate-600">
              This project was designed and developed to support library operations through a more organized and user-friendly digital platform.
            </p>
            <p className="mt-4 text-sm leading-7 text-slate-300 dark:text-slate-300/82 [html[data-theme='light']_&]:text-slate-600">
              The team contributed across development, system analysis, interface design, and documentation to deliver a school-aligned solution for catalog access, borrowing, notifications, and record monitoring.
            </p>
            <p className="mt-4 text-sm leading-7 text-slate-300 dark:text-slate-300/82 [html[data-theme='light']_&]:text-slate-600">
              The finished system reflects a shared effort to make daily library processes easier to manage for administrators, librarians, students, and faculty members.
            </p>
            <p className="mt-4 text-sm leading-7 text-slate-300 dark:text-slate-300/82 [html[data-theme='light']_&]:text-slate-600">
              Beyond implementation, the team also focused on presentation quality, usability, and workflow clarity so the platform would be practical for deployment and strong enough for academic presentation.
            </p>
            <div className="mt-6 rounded-[1.15rem] border border-cyan-300/12 bg-[linear-gradient(180deg,rgba(11,25,39,0.78),rgba(7,18,30,0.92))] p-4 shadow-[0_18px_36px_-24px_rgba(0,0,0,0.5)] lg:mt-auto [html[data-theme='light']_&]:border-sky-200/80 [html[data-theme='light']_&]:bg-[linear-gradient(180deg,rgba(248,252,255,0.98),rgba(232,244,255,0.94))] [html[data-theme='light']_&]:shadow-[0_18px_30px_-24px_rgba(14,116,144,0.2)]">
              <p className="text-[0.66rem] font-semibold uppercase tracking-[0.22em] text-cyan-300 [html[data-theme='light']_&]:text-sky-700">Project Summary</p>
              <p className="mt-3 text-sm leading-7 text-slate-300/92 [html[data-theme='light']_&]:text-slate-600">
                A multi-role library platform built for catalog browsing, borrowing workflows, return monitoring, penalties, and school-aligned user access.
              </p>
              <div className="mt-4 flex flex-wrap gap-2">
                {["Admin", "Librarian", "Student", "Faculty"].map((role) => (
                  <span
                    key={role}
                    className="inline-flex rounded-full border border-cyan-300/18 bg-cyan-300/10 px-3 py-1 text-[0.72rem] font-semibold uppercase tracking-[0.14em] text-cyan-100 [html[data-theme='light']_&]:border-sky-200 [html[data-theme='light']_&]:bg-sky-50 [html[data-theme='light']_&]:text-sky-800"
                  >
                    {role}
                  </span>
                ))}
              </div>
            </div>
          </div>

          <div className="grid min-w-0 gap-3 sm:gap-4">
            {builderProfiles.map((builder) => (
              <article
                key={builder.name}
                className="group overflow-hidden rounded-[1.2rem] border border-[#75a8db]/20 bg-[linear-gradient(135deg,rgba(7,16,28,0.96),rgba(10,24,40,0.9)_48%,rgba(8,18,30,0.96))] shadow-[0_18px_45px_-28px_rgba(0,0,0,0.45)] backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/38 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] [html[data-theme='light']_&]:border-sky-200/90 [html[data-theme='light']_&]:bg-[linear-gradient(135deg,rgba(255,255,255,0.998),rgba(246,250,255,0.995)_48%,rgba(236,245,255,0.99))] [html[data-theme='light']_&]:shadow-[0_18px_34px_-24px_rgba(14,116,144,0.12)] [html[data-theme='light']_&]:hover:border-sky-300/90 [html[data-theme='light']_&]:hover:shadow-[0_24px_40px_-24px_rgba(59,130,246,0.18)] sm:rounded-[1.6rem] xl:grid xl:min-h-[296px] xl:grid-cols-[220px_minmax(0,1fr)] xl:items-stretch dark:border-white/10 dark:bg-white/5"
              >
                <div className="relative h-[220px] overflow-hidden border-b border-white/10 sm:h-[240px] xl:h-full xl:min-h-[296px] xl:border-b-0 xl:border-r [html[data-theme='light']_&]:border-sky-200/80">
                  <img
                    src={builder.src}
                    alt={builder.name}
                    className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
                    style={{ objectPosition: builder.position }}
                  />
                  <div className="absolute inset-0 bg-[linear-gradient(180deg,rgba(5,10,18,0.06),rgba(5,10,18,0.16)_38%,rgba(5,10,18,0.68))] xl:bg-[linear-gradient(90deg,rgba(6,12,20,0.08),rgba(6,12,20,0.18)_38%,rgba(6,12,20,0.5))] [html[data-theme='light']_&]:bg-[linear-gradient(180deg,rgba(255,255,255,0.04),rgba(255,255,255,0.08)_36%,rgba(233,244,255,0.24))] [html[data-theme='light']_&]:xl:bg-[linear-gradient(90deg,rgba(255,255,255,0.06),rgba(244,249,255,0.14)_36%,rgba(224,239,255,0.3))]" />
                  <div className="absolute inset-x-0 bottom-0 h-24 bg-[linear-gradient(180deg,rgba(0,0,0,0),rgba(2,6,12,0.72))] [html[data-theme='light']_&]:bg-[linear-gradient(180deg,rgba(255,255,255,0),rgba(232,243,255,0.46))] xl:hidden" />
                </div>
                <div className="relative p-5 sm:p-6 xl:p-7">
                  <div className="absolute inset-y-0 right-0 hidden w-28 bg-[radial-gradient(circle_at_center,rgba(56,189,248,0.14),transparent_72%)] xl:block [html[data-theme='light']_&]:bg-[radial-gradient(circle_at_center,rgba(96,165,250,0.12),transparent_72%)]" />
                  <p className="relative text-[0.66rem] font-semibold uppercase tracking-[0.26em] text-cyan-300 [html[data-theme='light']_&]:text-sky-600 sm:text-xs">Development Team</p>
                  <p className="relative mt-2 text-[1.28rem] font-semibold tracking-[-0.02em] text-white [html[data-theme='light']_&]:text-slate-900 sm:text-[1.5rem]">{builder.name}</p>
                  <div className="relative mt-3 inline-flex rounded-full border border-cyan-200/35 bg-cyan-300/16 px-3.5 py-1.5 text-[0.72rem] font-bold uppercase tracking-[0.16em] text-cyan-50 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)] [html[data-theme='light']_&]:border-sky-200/90 [html[data-theme='light']_&]:bg-sky-50 [html[data-theme='light']_&]:text-sky-700 [html[data-theme='light']_&]:shadow-[inset_0_1px_0_rgba(255,255,255,0.75)]">
                    {builder.badge}
                  </div>
                  <p className="relative mt-4 max-w-[42ch] text-[0.98rem] font-semibold leading-7 text-sky-100 [html[data-theme='light']_&]:text-slate-800">{builder.role}</p>
                  <p className="relative mt-3 max-w-[46ch] text-[0.95rem] leading-7 text-slate-200/95 [html[data-theme='light']_&]:text-slate-600">{builder.note}</p>
                </div>
              </article>
            ))}
          </div>
        </RevealSection>

        <RevealSection id="contact" delay={130} className="rounded-[1.35rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-[18px] shadow-[0_22px_55px_-28px_rgba(0,0,0,0.45)] sm:rounded-[2rem] sm:p-8 dark:border-white/10 dark:bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,17,25,0.82))]">
          <div className="grid gap-6">
            <div>
              <p className="text-xs uppercase tracking-[0.24em] text-cyan-700 dark:text-cyan-300">Contact</p>
              <h2 className="mt-2 max-w-[14ch] text-3xl font-semibold tracking-tight text-white dark:text-white">Reach the campus and library support team through the official public channels.</h2>
              <p className="mt-4 text-sm leading-7 text-slate-300 dark:text-slate-300/82">
                This section keeps the school location and support details visible for visitors who arrive on the landing page before signing in.
              </p>
              <div className="mt-6">
                <a
                  href="https://www.regismariecollege.com/"
                  target="_blank"
                  rel="noreferrer"
                  className={`inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-cyan-400/10 px-5 py-3 text-sm font-medium text-cyan-800 transition-transform duration-200 hover:-translate-y-0.5 dark:text-cyan-300 ${focusBase}`}
                >
                  Visit official school page
                  <ArrowRight className="h-4 w-4" />
                </a>
              </div>
            </div>

            <div className="grid gap-3 sm:gap-4 sm:grid-cols-3">
              {contacts.map((item) => {
                const itemHref =
                  item.href ||
                  (item.label === "Address"
                    ? "https://www.google.com/maps/search/?api=1&query=Villanueva+Village+Basketball+Court+Lire+Ln+Paranaque+1709+Metro+Manila"
                    : null);

                return itemHref ? (
                  <a
                    key={item.label}
                    href={itemHref}
                    className="h-full rounded-[1.1rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-4 backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:text-cyan-300 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[1.5rem] sm:p-5 dark:border-white/10 dark:bg-white/5"
                  >
                    <div className="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-[#a7f3ff]/15 bg-[linear-gradient(145deg,rgba(34,211,238,0.2),rgba(96,165,250,0.14))] text-[#c9f7ff] shadow-[0_10px_24px_-14px_rgba(34,211,238,0.55)] dark:text-cyan-300">
                      <item.icon className="h-5 w-5" />
                    </div>
                    <p className="mt-4 text-sm text-slate-400 dark:text-slate-400">{item.label}</p>
                    <p className="mt-2 text-sm font-medium leading-7 text-white transition-colors dark:text-white">{item.value}</p>
                  </a>
                ) : (
                  <article
                    key={item.label}
                    className="h-full rounded-[1.1rem] border border-[#75a8db]/15 bg-[linear-gradient(180deg,rgba(8,18,30,0.14),rgba(8,18,30,0.82))] p-4 backdrop-blur-[8px] transition-all duration-300 hover:-translate-y-1 hover:border-[#98d9ff]/30 hover:shadow-[0_26px_60px_-28px_rgba(18,54,84,0.82)] sm:rounded-[1.5rem] sm:p-5 dark:border-white/10 dark:bg-white/5"
                  >
                    <div className="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-[#a7f3ff]/15 bg-[linear-gradient(145deg,rgba(34,211,238,0.2),rgba(96,165,250,0.14))] text-[#c9f7ff] shadow-[0_10px_24px_-14px_rgba(34,211,238,0.55)] dark:text-cyan-300">
                      <item.icon className="h-5 w-5" />
                    </div>
                    <p className="mt-4 text-sm text-slate-400 dark:text-slate-400">{item.label}</p>
                    <p className="mt-2 text-sm font-medium leading-7 text-white dark:text-white">{item.value}</p>
                  </article>
                );
              })}

            </div>
          </div>
        </RevealSection>
      </main>

      <footer className="border-t border-slate-200/70 py-8 dark:border-white/10">
        <div className="mx-auto flex w-full max-w-[1380px] flex-col gap-3 px-0 md:flex-row md:items-center md:justify-between">
          <div>
            <p className="text-sm font-semibold text-slate-900 dark:text-white">Regis Marie College Library Management System</p>
            <p className="text-xs text-slate-500 dark:text-slate-400">Designed to reflect the school identity and streamline library access.</p>
          </div>
          <div className="flex flex-wrap gap-4 text-xs text-slate-500 dark:text-slate-400">
            <a href="#top" onClick={(event) => handleNavClick(event, "#top")} className="transition-colors hover:text-cyan-600 dark:hover:text-cyan-300">
              Back to top
            </a>
            <a href="/loginpage.php" className="transition-colors hover:text-cyan-600 dark:hover:text-cyan-300">
              Portal login
            </a>
            <a href="/feedback.php" className="transition-colors hover:text-cyan-600 dark:hover:text-cyan-300">
              Feedback
            </a>
          </div>
        </div>
      </footer>
    </div>
  );
}
