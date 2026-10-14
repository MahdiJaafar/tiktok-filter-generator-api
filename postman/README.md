# Postman API collection

Import `TikTok_Filter_Generator_API.postman_collection.json` and the optional
`TikTok_Filter_Generator_Local.postman_environment.json` into Postman. Select the
local environment. The collection uses the [Postman Collection v2.1 format](https://schema.getpostman.com/json/collection/v2.1.0/docs/index.html).

1. Start the configured Laravel project with `php artisan serve`.
2. Set `base_url` to `http://127.0.0.1:8000` (no trailing slash or `/api`).
3. Enter your test `name`, `email` and `password` locally in the environment.
   Credentials are intentionally blank in the supplied files. Registration needs
   a unique email and a confirmed password of at least 8 characters, at most
   72 bytes with the default bcrypt driver. Null bytes are rejected.
4. Send **Authentication → Register** or **Login**. Successful responses save
   `token` in collection variables; protected requests inherit Bearer auth.
5. Set collection variables `category_id` and `tag_id` to existing database IDs.
   Their default `1` values are illustrative. There are no category/tag listing
   or management endpoints. IDs can come from existing gallery responses or
   your local database. A category is required to create a filter; tags can be
   omitted or set to `[]`. An empty database needs a category provisioned outside
   this collection.
6. Optionally send **Assets → Upload asset** after choosing a file in the Body
   tab. It saves `asset_id`. Leave the multipart Content-Type header to Postman.
   PNG/JPG/JPEG/WEBP only, up to 5120 KiB, 1–4096 pixels per axis. Storage is private;
   no upload file paths are bundled and no serving/deletion endpoints exist.
7. Send **Filters → Create filter** to save `filter_id`, then show/update/publish
   it. The example includes all four layer types and a tag. `asset_id` may be
   replaced with JSON `null` to omit the asset reference. Numeric variables are
   quoted in JSON; Laravel accepts numeric strings for these integer rules.
8. Browse **Public Gallery**, enable optional search/category/tag query parameters,
   like a published filter, create a collection (saves `collection_id`), save a
   published filter, or remix it. Remix saves `remix_id` while preserving the source
   `filter_id`; copy `remix_id` into `filter_id` when you want to edit the new draft.
9. Use delete requests and logout when finished. Logout revokes only the current
   token and clears its collection variable.

Run requests individually: this is documentation, not a sequential Collection
Runner scenario. Logout/delete requests occur before some dependent requests in
folder order. Public browsing uses **No Auth**. Collection variables hold runtime
tokens and IDs; the supplied environment only overrides the base URL and account
inputs. Avoid adding environment variables named `token`, `filter_id`, `asset_id`,
`collection_id` or `remix_id`, as they would shadow values saved by the scripts.
Clear passwords and runtime tokens before sharing or exporting.

The seven folders contain **25 requests**, covering **23 route entries**, including
both PUT and PATCH update methods. Each request has a saved success example;
error examples cover 401, 403, 404, 422 and 429 where applicable. Examples are
independent, synthetic scenarios; IDs, counts and timestamps will differ locally.
No password or real token appears in saved responses.

- Filters and collections: both PUT and PATCH apply partial updates. Supplying
  `tags` or `layers` replaces that entire relation; `[]` clears it. Omitted
  relations remain unchanged. Only the owner can use private CRUD endpoints.
- Collection details hide unpublished filter content. `filters_count` counts
  all saved links, so it can exceed the number of visible filters. Removing an
  unpublished saved filter remains allowed.
- Public gallery, likes and remixing require `status=published` and a non-null
  `published_at`. Remixes copy description, category, tags and layers into a new
  owned draft; inherited asset references remain editable. There is no unpublish API.
- List endpoints use 15 items per page with `data`, `links` and `meta`. Public
  filter responses include `creator` (id/name only) and `likes_count`; private
  filter CRUD responses omit those unloaded fields.
- Rate limits: login 5/minute per IP + normalized email and 30/minute per IP;
  registration 5/minute per IP; uploads 10/minute per user. A 429 response includes
  `Retry-After`. Failed validation attempts also consume these limits.

No API keys or additional Postman secrets are required. The collection does not
create categories/tags, seed a database, or invoke unimplemented endpoints.
