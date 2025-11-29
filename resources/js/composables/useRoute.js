import { usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

export function useRoute() {
    const page = usePage()

    return (name, params, absolute) => {
        return ziggyRoute(name, params, absolute, page.props.ziggy)
    }
}
