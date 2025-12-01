import { ref, onMounted } from 'vue';

// Initialize from localStorage immediately (before mount) to prevent flash
const getInitialTheme = () => {
    if (typeof window !== 'undefined') {
        return localStorage.getItem('theme') || 'dark';
    }
    return 'dark';
};

const theme = ref(getInitialTheme());

// Apply theme immediately on script load
if (typeof document !== 'undefined') {
    if (theme.value === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

export function useTheme() {
    onMounted(() => {
        // Re-apply theme on mount to ensure it's correct
        applyTheme(theme.value);
    });

    function applyTheme(newTheme) {
        if (newTheme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }

    function toggleTheme() {
        theme.value = theme.value === 'light' ? 'dark' : 'light';
        localStorage.setItem('theme', theme.value);
        applyTheme(theme.value);
    }

    function setTheme(newTheme) {
        theme.value = newTheme;
        localStorage.setItem('theme', newTheme);
        applyTheme(newTheme);
    }

    return {
        theme,
        toggleTheme,
        setTheme,
    };
}
