<x-filament-panels::page>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>

    <div
        x-data="{
            lightboxUrl: null,
            cropOpen: false,
            cropPath: null,
            cropper: null,
            ratios: [
                { label: 'Free', value: NaN },
                { label: '1:1', value: 1 },
                { label: '16:9', value: 16 / 9 },
                { label: '4:3', value: 4 / 3 },
                { label: '3:4', value: 3 / 4 },
            ],
            activeRatio: NaN,
            openCrop(path, url) {
                this.cropPath = path;
                this.cropOpen = true;
                this.activeRatio = NaN;
                this.$nextTick(() => {
                    const img = this.$refs.cropImg;
                    img.src = url;
                    if (this.cropper) { this.cropper.destroy(); }
                    img.onload = () => {
                        this.cropper = new Cropper(img, { viewMode: 1, aspectRatio: NaN, autoCropArea: 1 });
                    };
                });
            },
            setRatio(value) {
                this.activeRatio = value;
                if (this.cropper) { this.cropper.setAspectRatio(value); }
            },
            closeCrop() {
                this.cropOpen = false;
                if (this.cropper) { this.cropper.destroy(); this.cropper = null; }
            },
            saveCrop() {
                if (! this.cropper) return;
                const d = this.cropper.getData(true);
                $wire.cropFile(this.cropPath, d.x, d.y, d.width, d.height).then(() => this.closeCrop());
            },
        }"
    >
        <div class="pa-media-grid">
            @forelse ($this->getFiles() as $file)
                <div class="pa-media-tile" @click="lightboxUrl = '{{ $file['url'] }}'">
                    <img src="{{ $file['url'] }}" alt="{{ basename($file['path']) }}" loading="lazy">
                    <div class="pa-media-tile-overlay" @click.stop>
                        <span class="pa-media-tile-name">{{ basename($file['path']) }}</span>
                        <div class="pa-media-tile-actions">
                            <a href="{{ $file['url'] }}" download="{{ basename($file['path']) }}">Download</a>
                            <button type="button" @click="openCrop('{{ $file['path'] }}', '{{ $file['url'] }}')">Edit / Crop</button>
                            <button
                                type="button"
                                class="pa-media-danger"
                                wire:click="deleteFile('{{ $file['path'] }}')"
                                wire:confirm="Delete this file? Anything still referencing it will show a broken image."
                            >Delete</button>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm" style="color: var(--pa-muted)">No uploaded images yet — page-builder Image blocks and the Theme Settings logo/favicon will show up here once uploaded.</p>
            @endforelse
        </div>

        <div class="pa-media-lightbox" x-show="lightboxUrl" x-cloak @click="lightboxUrl = null" style="display: none;">
            <button type="button" class="pa-media-lightbox-close" @click.stop="lightboxUrl = null">&times;</button>
            <img :src="lightboxUrl" @click.stop>
        </div>

        <div class="pa-media-lightbox" x-show="cropOpen" x-cloak @click.self="closeCrop()" style="display: none;">
            <div class="pa-media-cropper-wrap" @click.stop>
                <div class="pa-media-ratio-btns">
                    <template x-for="r in ratios" :key="r.label">
                        <button
                            type="button"
                            :class="{ 'is-active': (isNaN(r.value) && isNaN(activeRatio)) || r.value === activeRatio }"
                            @click="setRatio(r.value)"
                            x-text="r.label"
                        ></button>
                    </template>
                </div>
                <div class="pa-media-cropper-img-ctn">
                    <img x-ref="cropImg" style="max-width:100%">
                </div>
                <div class="pa-media-cropper-actions">
                    <button type="button" @click="closeCrop()">Cancel</button>
                    <button type="button" class="pa-media-primary" @click="saveCrop()">Save crop</button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
