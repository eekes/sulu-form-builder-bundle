// @flow
export default function stripTags(html: mixed): string {
    return String(html === null || html === undefined ? '' : html).replace(/<[^>]*>/g, '').trim();
}
