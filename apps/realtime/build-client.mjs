import { build } from 'esbuild';

await build({
    entryPoints: ['client.js'],
    bundle: true,
    format: 'iife',
    globalName: 'ThreeebsRealtime',
    outfile: 'dist/realtime-client.js',
    plugins: [{
        name: 'threeebs-monaco-shim',
        setup(builder) {
            builder.onResolve(
                { filter: /^monaco-editor\/esm\/vs\/editor\/editor\.api\.js$/ },
                () => ({ path: new URL('./monaco-shim.js', import.meta.url).pathname })
            );
        }
    }]
});
