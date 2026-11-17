(() => {
    const savedTheme = localStorage.getItem('theme') || 'system';
    const systemDark = window.matchMedia(
        '(prefers-color-scheme: light)'
    ).matches;

    const theme = savedTheme === 'system'
        ? (systemDark ? 'dark' : 'light')
        : savedTheme;

    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;
})();