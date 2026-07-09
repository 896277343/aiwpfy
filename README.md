# Polylang OpenAI Translator

A small WordPress plugin that works alongside Polylang to translate posts, pages, and other public Polylang-managed post types with the OpenAI Responses API.

## What it does

- Adds a settings page at `Settings -> Polylang OpenAI Translator`.
- Stores your API key in WordPress options and sends requests only from the server.
- Supports official OpenAI endpoints and OpenAI-compatible API providers.
- Adds an `OpenAI Translation` box in the post editor.
- Creates a draft translation for the selected target language, or updates the existing linked translation.
- Can translate the current post to all configured target languages sequentially from the editor box.
- Can publish all linked translated drafts from the editor box after review.
- Uses Polylang APIs to set the target language and link translations.
- Preserves Gutenberg block comments, HTML, shortcodes, URLs, product codes, featured image, page template, and public taxonomy terms where possible.
- Translates Elementor page content stored in `_elementor_data` while preserving Elementor sections, widgets, IDs, images, links, and style settings.
- Optionally translates referenced media attachment title, alt text, caption, and description without duplicating the physical file.

## Install

1. Copy the `polylang-openai-translator` folder into `wp-content/plugins/`.
2. Activate `Polylang` first.
3. Activate `Polylang OpenAI Translator`.
4. Open `Settings -> Polylang OpenAI Translator`.
5. Add your API key, model, endpoint URL, and API format.

## OpenAI-compatible API setup

If you use your own OpenAI-compatible provider, configure:

- `OpenAI API Key`: the key required by your provider.
- `Model`: the model name required by your provider.
- `API Endpoint`: the full endpoint URL.
- `API Format`: choose the matching API shape.
- `Request timeout`: seconds WordPress should wait for each API request.

Examples:

- Official OpenAI Responses API: `https://api.openai.com/v1/responses`, format `Responses API`.
- Most compatible providers: `https://your-api-domain.com/v1/chat/completions`, format `Chat Completions`.

## Use

1. Edit the original page or post.
2. In the `OpenAI Translation` box, select a target language.
3. Click `Translate with OpenAI`.
4. Open the created draft translation, review it, then publish it.

Use `Translate all languages` to translate the current post to every other Polylang language. The plugin runs one language at a time to avoid a single long request timing out.

Use `Publish all translations` after review to publish linked translated posts in one click. The source post is not changed.

## Notes

- The plugin intentionally saves translated content as a draft so a human can review before publishing.
- Standard WordPress/Gutenberg content is translated from normal title, excerpt, and content fields.
- Elementor content is translated from `_elementor_data`; only common visible text fields are translated.
- Elementor pages skip redundant `post_content` translation and use larger text batches to reduce API round trips.
- Media metadata translation is off by default. Leave it off if your editor or page builder has trouble finding images when Polylang Media filtering is enabled.
- If media metadata translation is enabled, the featured image and Elementor image attachments used by the page are translated only when the original media already has a Polylang language. The original file is reused.
- Editor image pickers can show all language media by default, which helps when Polylang Media filtering makes Elementor or WordPress show only a few images.
- If you use other page builders that store content in custom post meta, add meta keys through the `pot_openai_translator_copy_meta_keys` filter to copy them. Custom translation support can be added by extending the plugin for that builder's data format.
- Very long pages may need a larger `Max output tokens` setting or may need to be translated in sections.
- If you see `cURL error 28`, increase `Request timeout` first. If it still happens with `0 bytes received`, the WordPress server may be unable to reach your API endpoint or the provider may not send a response before closing.
- API requests are forced to HTTP/1.1 to avoid HTTP/2 stream errors from some OpenAI-compatible gateways.
- Temporary API transport errors such as empty replies, timeouts, stream errors, and connection resets are retried automatically.
- If the admin box reports that the server returned HTML instead of JSON, the message usually points to a WordPress login page, PHP error page, host firewall page, or upstream gateway error.
- AJAX actions catch PHP exceptions and fatal errors where possible so server-side 500 errors can show a more useful message.

## Example meta copy filter

```php
add_filter( 'pot_openai_translator_copy_meta_keys', function ( $keys ) {
	$keys[] = '_yoast_wpseo_title';
	$keys[] = '_yoast_wpseo_metadesc';
	return $keys;
} );
```
