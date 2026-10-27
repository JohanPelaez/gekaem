<script>
    import AuthenticatedLayout from './AuthenticatedLayout.svelte';

    let { title = 'gipPyme', children } = $props();
</script>

<AuthenticatedLayout {title}>
    {@render children?.()}
</AuthenticatedLayout>
