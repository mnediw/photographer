### DIW Photographerfor TYPO3 (v12–v13)

PhotoSwipe gallery content element for TYPO3 that lets a specific frontend user mark/select images - especially helpful for photographers to let their customers mark the photos they want to purchase. Adds a lightbox with PhotoSwipe v5. Images can be served from public or private FAL storages; for private storages the extension ships a PSR-15 middleware that delivers files with access control.

#### Highlights
- Tool for Photopraphers to let customers mark photos they want to buy
- Optional restriction to a single FE user; only that user (when logged in) sees the gallery and can mark images
- Supports private storage, so files cant be downloaded without login
- Supports watermarking
- Per-user marked images are stored on the FE user record
- Max number of selectable images enforceable (client + server)
- PhotoSwipe v5 lightbox with an integrated Mark button
- Bootstrap-ready grid; configurable columns for md/lg
- Optional on-the-fly watermarking via secure middleware (works with public or private storages)

#### Future Features
- Support for collecting marked files for download in ZIP archive
- Support for online payment

---

### Requirements
- TYPO3 12.4 LTS or 13.x
- PHP 8.1+
- Recommended: Bootstrap Package by bk2k (for default button/grid styling)

Composer suggest:
```
bk2k/bootstrap-package: Our default templates and css depend on bootstrap
```

---

### Installation
1) Install via Composer
     ```bash
     composer require diw/photographer
     ```

2) Database update
   - Run the Install Tool/Upgrade Wizard

3) Auto-included configuration
   - New Content Element Wizard entry and TypoScript setup are auto-included; no need to add a static template manually.

4) Recommended theme
   - Install and enable the Bootstrap Package (`bk2k/bootstrap-package`) so the default template classes render nicely.

---

### Creating a frontend user and login form
The gallery can be restricted to exactly one frontend user; only this user (when logged in) may see and mark images.

1) Create a FE user (fe_users)
   - In the Backend, create a SysFolder page, e.g. “Frontend Users”.
   - Switch to the List module, open the SysFolder, and create a new “Frontend User”.
   - Set username, password, and (optionally) a user group. Save the record.

2) Add a login form to your site
   - Ensure core extension `felogin` is installed (it ships with TYPO3).
   - Create a page “Login” and insert a new content element: Plugins → “Login”.
   - In the plugin options, set the “Startingpoint” to your SysFolder with the FE users (or configure it globally via TypoScript).
   - Publish the page so users can log in.

3) Optional: Access testing
   - Log out, visit a page with the Photographer element restricted to your test user → the gallery should not render.
   - Log in as that FE user, then revisit the page → the gallery renders; mark buttons are visible and usable.

---

### Using the Photographer content element
1) Add the element
   - In the Page module, create a new content element: Media → “PhotoSwipe Gallery”.
   - Use the core “Media” field to select images and set their order.

2) Options (FlexForm)
   - Restrict to FE user (optional): select exactly one frontend user. If set, only this user can see/mark.
   - Max selectable images: 0 = unlimited; otherwise the limit is enforced client- and server-side.
   - Grid columns:
     - Columns on medium screens (md): 1–12 (default 3)
      - Columns on large screens (lg): 1–12 (default 3)

3) Optional watermark (Backend field)
   - You can add a watermark image per content element via the field “Watermark image (optional)”.
   - Recommended format: PNG with transparency. JPEG/WebP are supported too.
   - When set, the image will be composited onto every delivered gallery image by the file middleware before it is sent to the browser. By default, the watermark is scaled to ~25% of the image width and placed bottom-right with medium opacity.

4) Watermark options (FlexForm)
   - Position: top-left, top-right, centered, bottom-left, bottom-right (default: bottom-right)
   - Opacity (%): 0–100 (default: 50)
   - Size (%): 1–500 (default: 100). Applied relative to the base size (~25% of original image width)

5) PhotoSwipe options (from FlexForm)
   These map directly to PhotoSwipe v5 options (see link below). Defaults are chosen for sensible behavior:
   - initialZoomLevel (float, default 1.0)
   - secondaryZoomLevel (float, default 2.0)
   - maxZoomLevel (float, default 4.0)
   - mouseMovePan (boolean, default true)
   - showHideAnimationType (string: zoom|fade|none, default zoom)
   - bgOpacity (float, default 0.8)

4) Save and view
   - On the frontend, clicking an image opens the PhotoSwipe lightbox.
   - The star icon toggles marking; already selected images are shown as a filled star. The lightbox contains the same button.

---

### How marking works
- When a user toggles a mark, a POST request is sent to a PSR‑15 frontend middleware endpoint, which validates:
  - User is logged in
  - If “Restrict to FE user” is set, the current user matches
  - Image belongs to the current gallery (by `sys_file_reference`)
  - Optional “Max selectable” limit
- The user’s selections are stored as JSON in `fe_users.tx_photographer_marks`, keyed by the content element UID. Example structure:
  ```json
  {
    "123": [11, 35, 47]
  }
  ```

---

### PSR‑15 endpoints used by this extension
- File delivery (supports private storages + watermarking):
  - GET `/index.php?photographer_file=1&contentUid=UID&refUid=REF_UID`
  - Returns the requested image (200) or JSON errors (4xx) if access is denied. Public CEs get long‑lived cache headers; restricted CEs are `private, no-store`.
- Marking API:
  - POST `/index.php?photographer_mark=1`
  - Body fields: `contentUid`, `refUid`, `action` (`toggle`|`add`|`remove`)
  - Response JSON examples:
    - `{"success": true, "marked": [11,35]}`
    - `{"success": false, "error": "not_authenticated"}`
    - `{"success": false, "error": "forbidden"}`
    - `{"success": false, "error": "limit_reached", "max": 5}`

Notes
- The extension uses absolute URLs (`/index.php?...`) to avoid issues with `<base href>` or nested paths.
- The middlewares are registered in `Configuration/RequestMiddlewares.php` and run after FE authentication and before page rendering.

---

### Styling and assets
- The extension ships minimal CSS and a small ES module that initializes PhotoSwipe and manages the mark buttons.
- Assets are enqueued locally by the Fluid template via `<f:asset.css>` and `<f:asset.script>` when the content element is rendered.
- Default grid and buttons are based on Bootstrap classes; adjust in your SitePackage if needed.

---

### Link to PhotoSwipe documentation
- PhotoSwipe v5+ options and API: https://photoswipe.com/

---

### Troubleshooting
- Gallery not visible while a user is configured in FlexForm: ensure you’re logged in as exactly that FE user.
- Marking does not persist: verify that `fe_users` table contains the `tx_photographer_marks` column and that the FE user record is writable; also check browser devtools for the middleware response.
- Lightbox doesn’t open: if you use a public storage, ensure your images in `tt_content.media` have valid public URLs (FAL storage properly configured). If you use a private storage, ensure the built-in middleware URL `index.php?photographer_file=1&contentUid=...&refUid=...` is reachable and not blocked by rewrites or reverse proxy.
- Buttons look odd: ensure Bootstrap (or equivalent styling) is available, or adjust `Resources/Public/Css/photographer.css`.

---

### Using files stored outside the document_root (FAL storage)
It’s common to keep original images outside of the web server’s document root (for example `/var/data/photos`) and still use them in TYPO3. TYPO3’s File Abstraction Layer (FAL) fully supports this. This extension supports both public and private storages:
To prevent photos from being downloaded, use a protected storage:

Protected storage outside docroot (not publicly accessible)
- Create the desired folder outside the docroot first
- Create a new File Storage in TYPO3 Backend → Filelist → Storages → Create new
  - Driver: Local
  - Base path: absolute server path, e.g. `/var/data/photos/` or 
  - Base path: relative server path, e.g. `../photos`
  - Is public: No

How to use the storage in the CE
- After creating the storage, open the Filelist module and add folders/files under that storage.
- In your page’s Photographer content element, pick images via the core “Media” field; you can browse into your new storage as usual.
- Ensure that the storage’s Base URL actually works in the browser. If the grid shows broken thumbnails or PhotoSwipe doesn’t open, verify the alias/virtual directory mapping and clear TYPO3 caches.

Tips
- If your server maintains originals in a non-web path (e.g. `/mnt/originals`) but you want processed/downsized variants public, you can point the storage to a sync/mirrored folder and run a job to prepare web‑ready images.
- Set proper caching headers on the alias location (see examples above). PhotoSwipe loads the original image URLs directly; good caching improves performance.
