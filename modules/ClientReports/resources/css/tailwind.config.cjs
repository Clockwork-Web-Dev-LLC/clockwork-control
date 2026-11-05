// Tailwind v3 config for the client report pages. See report.css next to this
// file; build with `npm run build:report-css`. Stock v3 defaults, the same as
// the Play CDN the pages used to load, so the output renders identically.
module.exports = {
    content: {
        relative: true,
        files: ['../views/reports/*.blade.php'],
    },
};
