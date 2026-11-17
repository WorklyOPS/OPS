const toggle = document.getElementById('themeToggle');
const icon = document.getElementById('themeIcon');
const root = document.documentElement;

const sunIcon = `
    <circle cx="12" cy="12" r="4"></circle>
    <path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42
    m11.3 11.3 1.42 1.42M2 12h2m16 0h2
    M4.93 19.07l1.42-1.42m11.3-11.3
    1.42-1.42"/>
`;

const moonIcon = `
    <path d="M20.9 13A9 9 0 0 1 11 3.1
    9 9 0 1 0 20.9 13Z"/>
`;

function updateIcon(theme) {
    icon.innerHTML = theme === 'dark' ? moonIcon : sunIcon;
}

function applyTheme(theme) {
    root.dataset.theme = theme;
    root.style.colorScheme = theme;
    updateIcon(theme);
}

toggle.addEventListener('click', () => {
    const currentTheme = root.dataset.theme;
    const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

    localStorage.setItem('theme', nextTheme);

    const rect = toggle.getBoundingClientRect();

    root.style.setProperty('--x', `${rect.left + rect.width / 2}px`);
    root.style.setProperty('--y', `${rect.top + rect.height / 2}px`);

    if (document.startViewTransition) {
        document.startViewTransition(() => {
            applyTheme(nextTheme);
        });
    } else {
        applyTheme(nextTheme);
    }
});

updateIcon(root.dataset.theme);