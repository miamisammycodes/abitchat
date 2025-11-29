import { ref, watch, onMounted } from 'vue';

const theme = ref('light');

export function useTheme() {
    onMounted(() => {
        // Load saved theme from localStorage
        const savedTheme = localStorage.getItem('theme') || 'light';
        theme.value = savedTheme;
        applyTheme(savedTheme);
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
