import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

/* ------------------------------ Map Location ------------------------------ */

const TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
const STREET_ZOOM = 17;
const AREA_ZOOM = 12;
const COORDINATE_DIGITS = 6;

const PIN_HTML = `<svg viewBox="0 0 24 36" width="28" height="42" aria-hidden="true">
  <path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 24 12 24s12-15 12-24C24 5.4 18.6 0 12 0z" fill="#d93025" stroke="#8c1c13" stroke-width="1"/>
  <circle cx="12" cy="12" r="4.5" fill="#ffffff"/>
</svg>`;

/** Looks up an address with OpenStreetMap's Nominatim; null when nothing matches. */
async function geocode(query) {
    const url = `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(query)}`;
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`Geocoding failed with ${response.status}`);
    const results = await response.json();
    return results[0] ? { lat: Number(results[0].lat), lng: Number(results[0].lon) } : null;
}

const round = (value) => Number(value.toFixed(COORDINATE_DIGITS));

/** The pin, or null unless both coordinates are real numbers. */
function toPin(latitude, longitude) {
    if (latitude === '' || longitude === '' || latitude == null || longitude == null) return null;
    const lat = Number(latitude);
    const lng = Number(longitude);
    return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
}

/**
 * "Map Location": click the map (or drag the pin) to mark exactly where the customer is.
 * The pin is kept in the Livewire properties named by `latitude` and `longitude`.
 */
function mapLocation({ latitude, longitude, defaultArea, readOnly, homeAddress }) {
    let map = null;
    let marker = null;

    return {
        pin: null,
        query: '',
        searching: false,
        message: null,
        readOnly,

        init() {
            this.pin = toPin(this.$wire.get(latitude), this.$wire.get(longitude));

            map = L.map(this.$refs.map, { worldCopyJump: true }).setView([20, 0], 2);
            L.tileLayer(TILE_URL, { maxZoom: 19, attribution: ATTRIBUTION }).addTo(map);
            map.on('click', (event) => {
                if (!this.readOnly) this.place(event.latlng);
            });
            // The modal may still be laying out when the map is created.
            requestAnimationFrame(() => map?.invalidateSize());

            // Open on the pin, or on the company's area when nothing is pinned yet.
            if (this.pin) {
                map.setView([this.pin.lat, this.pin.lng], STREET_ZOOM);
                this.syncMarker();
            } else if (defaultArea) {
                geocode(defaultArea)
                    .then((found) => found && map?.setView([found.lat, found.lng], AREA_ZOOM))
                    .catch(() => {});
            }
        },

        destroy() {
            map?.remove();
            map = null;
            marker = null;
        },

        place(next) {
            this.pin = next ? { lat: round(next.lat), lng: round(next.lng) } : null;
            this.$wire.set(latitude, this.pin ? String(this.pin.lat) : '', false);
            this.$wire.set(longitude, this.pin ? String(this.pin.lng) : '', false);
            this.syncMarker();
            this.$dispatch('map-pin-changed');
        },

        syncMarker() {
            if (!map) return;
            if (!this.pin) {
                marker?.remove();
                marker = null;
                return;
            }
            if (!marker) {
                const icon = L.divIcon({ html: PIN_HTML, className: 'map-pin', iconSize: [28, 42], iconAnchor: [14, 42] });
                marker = L.marker([this.pin.lat, this.pin.lng], { icon, draggable: !this.readOnly, keyboard: false }).addTo(map);
                marker.on('dragend', () => this.place(marker.getLatLng()));
            } else {
                marker.setLatLng([this.pin.lat, this.pin.lng]);
            }
        },

        get pinText() {
            if (this.pin) return `Pinned at ${this.pin.lat.toFixed(COORDINATE_DIGITS)}, ${this.pin.lng.toFixed(COORDINATE_DIGITS)}`;
            return this.readOnly ? 'No location pinned.' : 'Click the map to drop a pin. Drag the pin to adjust it.';
        },

        /** The home address as one line for the map search; "" until an address or city is given. */
        homeAddressText() {
            return homeAddress();
        },

        async search(text) {
            if (!map || text.trim() === '') return;
            this.searching = true;
            this.message = null;
            try {
                const found = await geocode(text);
                if (found) {
                    map.setView([found.lat, found.lng], STREET_ZOOM);
                    this.message = 'Click the exact spot on the map to place the pin.';
                } else {
                    this.message = 'No place matched that search. Try fewer words, or move the map by hand.';
                }
            } catch {
                this.message = 'The map search is unavailable right now. Move the map by hand instead.';
            } finally {
                this.searching = false;
            }
        },
    };
}

/* ------------------------------ Photo cropper ----------------------------- */

/** Side of the square frame on screen, in CSS pixels. */
const FRAME = 280;
/** Side of the saved square photo, in pixels. */
const OUTPUT = 512;
const MAX_ZOOM = 4;
const KEY_STEP = 10;
const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** Screen pixels per image pixel; at zoom 1 the image just covers the frame. */
const scaleOf = (size, zoom) => (FRAME / Math.min(size.width, size.height)) * zoom;

/** Keeps the zoom in range and the frame fully covered by the image. */
function clampView(view, size) {
    const zoom = Math.min(MAX_ZOOM, Math.max(1, view.zoom));
    const scale = scaleOf(size, zoom);
    const maxX = (size.width * scale - FRAME) / 2;
    const maxY = (size.height * scale - FRAME) / 2;
    return {
        zoom,
        x: Math.min(maxX, Math.max(-maxX, view.x)),
        y: Math.min(maxY, Math.max(-maxY, view.y)),
    };
}

/** Zooms about the frame centre, so the face in the middle stays in the middle. */
function zoomTo(view, zoom, size) {
    const next = Math.min(MAX_ZOOM, Math.max(1, zoom));
    const ratio = next / view.zoom;
    return clampView({ zoom: next, x: view.x * ratio, y: view.y * ratio }, size);
}

/**
 * Picks a customer photo, lets the user frame it ("Adjust Photo"), then uploads the framed
 * square JPEG into the Livewire property named by `model`.
 */
function photoCropper({ model }) {
    let objectUrl = null;
    let drag = null;

    return {
        frame: FRAME,
        maxZoom: MAX_ZOOM,
        cropping: false,
        src: null,
        fileName: 'photo.jpg',
        size: null,
        view: { zoom: 1, x: 0, y: 0 },
        error: null,
        clientError: null,
        saving: false,
        uploading: false,
        /** The full picture the shown photo was framed from, so it can be re-framed. */
        original: null,
        pendingFile: null,

        choose(file) {
            this.$refs.file.value = '';
            if (!file) return;
            if (!PHOTO_MIME_TYPES.includes(file.type)) {
                this.clientError = `"${file.name}" is not a JPG, PNG or WebP image.`;
                return;
            }
            this.clientError = null;
            this.pendingFile = file;
            this.open(file, file.name);
        },

        adjust(shownUrl) {
            if (this.original) this.open(this.original, this.original.name);
            else if (shownUrl) this.open(shownUrl, 'photo.jpg');
        },

        open(source, fileName) {
            this.release();
            if (typeof source === 'string') {
                this.src = source;
            } else {
                objectUrl = URL.createObjectURL(source);
                this.src = objectUrl;
            }
            this.fileName = fileName;
            this.size = null;
            this.view = { zoom: 1, x: 0, y: 0 };
            this.error = null;
            this.cropping = true;
        },

        cancel() {
            this.cropping = false;
            this.pendingFile = null;
            this.release();
        },

        release() {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        },

        loaded(event) {
            const { naturalWidth: width, naturalHeight: height } = event.target;
            this.size = { width, height };
            this.view = { zoom: 1, x: 0, y: 0 };
        },

        get imageStyle() {
            if (!this.size) return 'visibility: hidden';
            const scale = scaleOf(this.size, this.view.zoom);
            return `width: ${this.size.width * scale}px; height: ${this.size.height * scale}px; transform: translate(-50%, -50%) translate(${this.view.x}px, ${this.view.y}px)`;
        },

        setZoom(zoom) {
            if (this.size) this.view = zoomTo(this.view, Number(zoom), this.size);
        },

        wheel(event) {
            if (this.size) this.view = zoomTo(this.view, this.view.zoom * Math.exp(-event.deltaY * 0.0015), this.size);
        },

        pointerDown(event) {
            if (!this.size || event.button !== 0) return;
            event.currentTarget.setPointerCapture(event.pointerId);
            drag = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, view: this.view };
        },

        pointerMove(event) {
            if (!this.size || !drag || drag.pointerId !== event.pointerId) return;
            this.view = clampView(
                { ...drag.view, x: drag.view.x + event.clientX - drag.startX, y: drag.view.y + event.clientY - drag.startY },
                this.size,
            );
        },

        pointerUp(event) {
            if (drag?.pointerId === event.pointerId) drag = null;
        },

        key(event) {
            if (!this.size) return;
            const moves = { ArrowLeft: [KEY_STEP, 0], ArrowRight: [-KEY_STEP, 0], ArrowUp: [0, KEY_STEP], ArrowDown: [0, -KEY_STEP] };
            const move = moves[event.key];
            if (move) {
                event.preventDefault();
                this.view = clampView({ ...this.view, x: this.view.x + move[0], y: this.view.y + move[1] }, this.size);
            } else if (event.key === '+' || event.key === '=') {
                event.preventDefault();
                this.view = zoomTo(this.view, this.view.zoom + 0.1, this.size);
            } else if (event.key === '-') {
                event.preventDefault();
                this.view = zoomTo(this.view, this.view.zoom - 0.1, this.size);
            }
        },

        apply() {
            const image = this.$refs.image;
            if (!image || !this.size) return;
            const scale = scaleOf(this.size, this.view.zoom);
            const side = FRAME / scale;
            const left = this.size.width / 2 - (FRAME / 2 + this.view.x) / scale;
            const top = this.size.height / 2 - (FRAME / 2 + this.view.y) / scale;

            const canvas = document.createElement('canvas');
            canvas.width = OUTPUT;
            canvas.height = OUTPUT;
            const context = canvas.getContext('2d');
            if (!context) {
                this.error = 'Your browser could not adjust this photo.';
                return;
            }
            // JPEG has no transparency; give transparent PNGs a white background.
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, OUTPUT, OUTPUT);
            context.imageSmoothingQuality = 'high';
            context.drawImage(image, left, top, side, side, 0, 0, OUTPUT, OUTPUT);

            this.saving = true;
            canvas.toBlob(
                (blob) => {
                    this.saving = false;
                    if (!blob) {
                        this.error = 'Your browser could not adjust this photo.';
                        return;
                    }
                    const name = `${this.fileName.replace(/\.[^.]*$/, '') || 'photo'}.jpg`;
                    const file = new File([blob], name, { type: 'image/jpeg' });
                    if (this.pendingFile) this.original = this.pendingFile;
                    this.pendingFile = null;
                    this.cropping = false;
                    this.release();
                    this.uploading = true;
                    this.$wire.upload(
                        model,
                        file,
                        () => (this.uploading = false),
                        () => {
                            this.uploading = false;
                            this.clientError = `"${name}" could not be uploaded. Please try again.`;
                        },
                    );
                },
                'image/jpeg',
                0.9,
            );
        },

        forget() {
            this.original = null;
            this.clientError = null;
        },
    };
}

/* ------------------------------- Page Guide ------------------------------- */

const GUIDE_PADDING = 6;
const GUIDE_GAP = 12;
const GUIDE_TICK_MS = 400;

/** A selector from App\Support\Guides: plain names are data-guide markers. */
function guideSelector(target) {
    return /^[a-z0-9-]+$/.test(target) ? `[data-guide="${target}"]` : target;
}

/** The first matching element that is on screen (not hidden), or null. */
function guideElement(target) {
    if (!target) {
        return null;
    }
    try {
        return [...document.querySelectorAll(guideSelector(target))].find((el) => el.getClientRects().length > 0) ?? null;
    } catch {
        return null;
    }
}

function guideCount(selector) {
    try {
        return document.querySelectorAll(guideSelector(selector)).length;
    } catch {
        return 0;
    }
}

/**
 * Spotlights one part of the page at a time with a note beside it, for the guides in
 * App\Support\Guides, fetched from urlTemplate with __GUIDE__ replaced by the guide's name. Started
 * with a "guide-start" window event naming the guide; a step moves on when its "grows" selector
 * gains matches or its "until" selector appears, or on Next. A step whose "requires" selector
 * disappears (its modal was closed) goes back to the last step before it without one.
 */
function pageGuide(urlTemplate) {
    return {
        guides: {},
        key: null,
        index: 0,
        open: false,
        spot: null,
        bubble: { top: 0, left: 0, placement: 'center' },
        startCount: 0,
        timer: null,

        init() {
            // Scrolling inside a modal or table doesn't reach window listeners, so follow it while capturing.
            document.addEventListener('scroll', () => this.open && this.place(), { capture: true, passive: true });
            document.addEventListener('focusin', () => this.open && this.place());
        },

        get guide() {
            return this.guides[this.key] ?? null;
        },
        get step() {
            return this.guide?.steps[this.index] ?? null;
        },
        get last() {
            return this.guide !== null && this.index === this.guide.steps.length - 1;
        },

        async start(key) {
            if (!this.guides[key]) {
                const response = await fetch(urlTemplate.replace('__GUIDE__', encodeURIComponent(key)), {
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) {
                    return;
                }
                this.guides[key] = await response.json();
            }
            this.key = key;
            this.index = 0;
            this.open = true;
            this.enter();
            clearInterval(this.timer);
            this.timer = setInterval(() => this.tick(), GUIDE_TICK_MS);
        },

        go(by) {
            const next = this.index + by;
            if (next < 0) {
                return;
            }
            if (next >= this.guide.steps.length) {
                this.close();
                return;
            }
            this.index = next;
            this.enter();
        },

        close() {
            this.open = false;
            this.spot = null;
            clearInterval(this.timer);
        },

        enter() {
            this.startCount = this.step.grows ? guideCount(this.step.grows) : 0;
            guideElement(this.step.target)?.scrollIntoView({ block: 'center' });
            this.$nextTick(() => this.place());
        },

        /** Follows the target as the page changes and moves on once the step's action is done. */
        tick() {
            if (!this.open) {
                return;
            }
            const { grows, until, requires } = this.step;
            if ((grows && guideCount(grows) > this.startCount) || (until && guideElement(until))) {
                this.go(1);
                return;
            }
            // The form this step is about was closed: go back to the step that opens it.
            if (requires && !guideElement(requires)) {
                const opener = this.guide.steps.findLastIndex((step, i) => i < this.index && !step.requires);
                this.index = Math.max(opener, 0);
                this.enter();
                return;
            }
            this.place();
        },

        place() {
            const el = guideElement(this.step?.target);
            const card = this.$refs.card;
            if (!el || !card) {
                this.spot = null;
                this.bubble = { top: 0, left: 0, placement: 'center' };
                return;
            }

            const rect = el.getBoundingClientRect();
            this.spot = {
                top: rect.top - GUIDE_PADDING,
                left: rect.left - GUIDE_PADDING,
                width: rect.width + GUIDE_PADDING * 2,
                height: rect.height + GUIDE_PADDING * 2,
            };

            const width = card.offsetWidth;
            const height = card.offsetHeight;
            const maxTop = window.innerHeight - height - 16;
            const maxLeft = window.innerWidth - width - 16;
            const left = Math.min(Math.max(rect.left, 16), maxLeft);

            // Places to try, best first: beside a modal (so its other fields stay visible), below or
            // above the target, then the bottom or top of the screen.
            const spots = [];
            const dialog = el.closest('.modal-dialog')?.getBoundingClientRect();
            if (dialog) {
                const top = Math.min(Math.max(rect.top, 16), maxTop);
                spots.push({ top, left: dialog.right + GUIDE_GAP, placement: 'side' });
                spots.push({ top, left: dialog.left - GUIDE_GAP - width, placement: 'side' });
            }
            spots.push({ top: rect.bottom + GUIDE_PADDING + GUIDE_GAP, left, placement: 'below' });
            spots.push({ top: rect.top - GUIDE_PADDING - GUIDE_GAP - height, left, placement: 'above' });
            spots.push({ top: maxTop, left: maxLeft, placement: 'pinned' });
            spots.push({ top: 16, left: maxLeft, placement: 'pinned' });

            // Never cover the field being typed in.
            const field = document.activeElement?.matches('input, select, textarea') && !card.contains(document.activeElement)
                ? document.activeElement.getBoundingClientRect()
                : null;
            const fits = (spot) => spot.top >= 16 && spot.top <= maxTop && spot.left >= 16 && spot.left <= maxLeft;
            const covers = (spot, box) => box !== null
                && spot.left < box.right && spot.left + width > box.left
                && spot.top < box.bottom && spot.top + height > box.top;

            this.bubble = spots.find((spot) => fits(spot) && !covers(spot, field))
                ?? spots.find(fits)
                ?? spots.at(-2);
        },

        spotStyle() {
            return this.spot
                ? `top:${this.spot.top}px;left:${this.spot.left}px;width:${this.spot.width}px;height:${this.spot.height}px`
                : '';
        },

        cardStyle() {
            return this.bubble.placement === 'center' ? '' : `top:${this.bubble.top}px;left:${this.bubble.left}px`;
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mapLocation', mapLocation);
    window.Alpine.data('photoCropper', photoCropper);
    window.Alpine.data('pageGuide', pageGuide);
});
