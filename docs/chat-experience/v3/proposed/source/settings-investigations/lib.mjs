// Helpers for the Settings and Investigations boards. Mirrors docs/chat-experience/v3/source/c3lib.mjs
// (its lib.mjs import is gone), writes plain HTML on the three board stylesheets.
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
export const OUT = join(HERE, '..', 'new-boards');

// people (avatar colours are the boards' existing avatar hexes, reused, none new)
export const P = {
    rohan: ['RP', '#3e6aa8', 'Rohan Patel', 'Site lead · Kōwhai House'],
    ana: ['AR', '#a0563f', 'Ana Rēweti', 'Support worker · Kōwhai House'],
    hana: ['HW', '#3f7f6a', 'Hana Wilson', 'Support worker · Kōwhai House'],
    tane: ['TW', '#3e6aa8', 'Tane Wineera', 'Person supported · Kōwhai House'],
    kiri: ['KW', '#8a6d2f', 'Kiri Wineera', 'Whānau · Mum'],
    aroha: ['AN', '#3f7f6a', 'Aroha Ngata', 'Person supported · Kōwhai House'],
    admin: ['DA', '#7a5aa8', 'Demo Admin', 'Administrator'],
    mere: ['MT', '#7a5aa8', 'Mere Tūhoe', 'HR investigator'],
};

// lucide-shaped glyphs (24×24, stroke 2)
export const I = {
    chat: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    chattext: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 13h5"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    userx: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 8 5 5M22 8l-5 5"/>',
    userplus: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    usercheck: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
    phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
    video: '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
    screen: '<path d="M13 3H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-3"/><path d="M8 21h8M12 17v4"/><path d="m17 8 5-5M17 3h5v5"/>',
    clip: '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
    mic: '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v3"/>',
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    phonedev: '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    shieldcheck: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
    shieldalert: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 8v4M12 16h.01"/>',
    lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    eye: '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>',
    eyeoff: '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49M14.084 14.158a3 3 0 0 1-4.242-4.242M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143M2 2l20 20"/>',
    history: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
    undo: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
    refresh: '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    building: '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2M10 6h4M10 10h4M10 14h4M10 18h4"/>',
    home: '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    sticker: '<path d="M15.5 3H5a2 2 0 0 0-2 2v14c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2V8.5L15.5 3Z"/><path d="M15 3v4a2 2 0 0 0 2 2h4"/><path d="M8 13h.01M16 13h.01M10 16s.8 1 2 1c1.3 0 2-1 2-1"/>',
    smile: '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
    sparkles: '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
    activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    server: '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
    filedown: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M12 18v-6M9 15l3 3 3-3"/>',
    filetext: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M10 9H8M16 13H8M16 17H8"/>',
    file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    archive: '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/>',
    hash: '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
    pencil: '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
    trash: '<path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    sliders: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
    chev: '<path d="m6 9 6 6 6-6"/>',
    chevr: '<path d="m9 18 6-6-6-6"/>',
    chevl: '<path d="m15 18-6-6 6-6"/>',
    arrowur: '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    checkc: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    plus: '<path d="M5 12h14M12 5v14"/>',
    copy: '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    more: '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    kebab: '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
    moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    volume: '<path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6M19.364 18.364a9 9 0 0 0 0-12.728"/>',
    aa: '<path d="M21 14h-5M16 16v-3.5a2.5 2.5 0 0 1 5 0V16M4.5 13h6M3 16l4.5-9 4.5 9"/>',
    keyboard: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M10 8h.01M12 12h.01M14 8h.01M16 12h.01M18 8h.01M6 8h.01M7 16h10M8 12h.01"/>',
    palette: '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
    key: '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
    plug: '<path d="M12 22v-5M9 8V2M15 8V2M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
    briefcase: '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
    folder: '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
    timer: '<path d="M10 2h4M12 14l3-3"/><circle cx="12" cy="14" r="8"/>',
    radio: '<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5M19.1 4.9C23 8.8 23 15.1 19.1 19"/>',
    inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
    layers: '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65M22 12.65l-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
    megaphone: '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    hand: '<path d="M18 11V6a2 2 0 0 0-4 0"/><path d="M14 10V4a2 2 0 0 0-4 0v2"/><path d="M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/>',
    stop: '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
    pin: '<path d="M12 17v5M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
    at: '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>',
    phonemiss: '<path d="M16 2l6 6M22 2l-6 6"/><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
    reply: '<path d="m9 17-5-5 5-5"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
    gauge: '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
    database: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
    paint: '<path d="m14.622 17.897-10.68-2.913M18.376 2.622a1 1 0 1 1 3.002 3.002L17.36 9.643a.5.5 0 0 0 0 .707l.944.944a2.41 2.41 0 0 1 0 3.408l-.944.944a.5.5 0 0 1-.707 0L8.354 7.348a.5.5 0 0 1 0-.707l.944-.944a2.41 2.41 0 0 1 3.408 0l.944.944a.5.5 0 0 0 .707 0z"/><path d="M9 8c-1.804 2.71-3.97 3.46-6.583 3.948a.507.507 0 0 0-.302.819l7.32 8.883a1 1 0 0 0 1.185.204C12.735 20.405 16 16.792 16 15"/>',
    boxes: '<path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19v-5.5l-5-3-4.03 2.42Z"/><path d="m7 16.5-4.74-2.85M7 16.5l5-3M7 16.5v5.17M12 13.5V19l3.97 2.38a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L17 10.5l-5 3Z"/><path d="m17 16.5-5-3M17 16.5l4.74-2.85M17 16.5v5.17M7.97 4.42A2 2 0 0 0 7 6.13v4.37l5 3 5-3V6.13a2 2 0 0 0-.97-1.71l-3-1.8a2 2 0 0 0-2.06 0l-3 1.8Z"/>',
    ring: '<circle cx="12" cy="12" r="9"/>',
};

export function svg(k, s = 16, cls = '') {
    if (!I[k]) throw new Error('no icon ' + k);
    const w = k === 'kebab' || k === 'more' ? 2.4 : 2;
    return `<svg${cls ? ` class="${cls}"` : ''} width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${w}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${I[k]}</svg>`;
}
export const nm = (who) => P[who][2];
export const av = (who, size = '', state = '') => `<span class="ch-av${size ? ' ' + size : ''}${state ? ' ' + state : ''}" style="background: ${P[who][1]}" aria-hidden="true">${P[who][0]}</span>`;
export const lens = (icon, cls = '', s = 15) => `<span class="ch-lens${cls ? ' ' + cls : ''}">${svg(icon, s)}</span>`;
export const btn = (label, kind = 'o', o = {}) =>
    `<button class="oc-btn ${kind}${o.sm ? ' sm' : ''}${o.xs ? ' xs' : ''}${o.dis ? ' dis' : ''}${o.cls ? ' ' + o.cls : ''}" type="button"${o.dis ? ' disabled' : ''}${o.aria ? ` aria-label="${o.aria}"` : ''}${o.style ? ` style="${o.style}"` : ''}>${o.icon ? svg(o.icon, o.sm || o.xs ? 14 : 15) : ''}${label}${o.after ? svg(o.after, 14) : ''}</button>`;
export const badge = (t, tone = 'neutral', sm = true) => `<span class="oc-badge ${tone}${sm ? ' sm' : ''}">${t}</span>`;
export const cap = (t) => `<span class="ch-cap">${t}</span>`;
export const col = (label, inner, style = '') => `<div class="ch-col"${style ? ` style="${style}"` : ''}>${cap(label)}${inner}</div>`;
export const sec = (title, sub, inner) => `<section class="ch-sec"><h2>${title}<small>${sub}</small></h2>${inner}</section>`;
export const note = (text, tone = '', icon = 'info') => `<div class="ch-note${tone ? ' ' + tone : ''}">${svg(icon, 15)}<div>${text}</div></div>`;
export const callout = (text, tone = '', icon = 'info') => `<div class="oc-callout${tone ? ' ' + tone : ''}">${svg(icon, 16)}<div>${text}</div></div>`;
export const field = (label, inner, hint = '') => `<div class="oc-field"><span class="oc-lbl">${label}</span>${inner}${hint ? `<span class="oc-hint">${hint}</span>` : ''}</div>`;
export const input = (val, cls = '', style = '') => `<div class="oc-input${cls ? ' ' + cls : ''}"${style ? ` style="${style}"` : ''}>${val}</div>`;
export const sel = (val, cls = '', style = '') => `<button class="ch-sel${cls ? ' ' + cls : ''}" type="button" aria-haspopup="listbox"${style ? ` style="${style}"` : ''}>${val}${svg('chev', 15, 'chev')}</button>`;
export const box = (on = false, label = '', sub = '', dis = false) => `<label class="ch-chk"><span class="oc-box${on ? ' on' : ''}${dis ? ' dis' : ''}" aria-hidden="true">${on ? svg('check', 12) : ''}</span><span>${label}${sub ? `<small>${sub}</small>` : ''}</span></label>`;
export const radio = (on, label, sub = '') => `<label class="ch-chk"><span class="oc-radio${on ? ' on' : ''}" aria-hidden="true"></span><span>${label}${sub ? `<small>${sub}</small>` : ''}</span></label>`;
export const link = (t, icon = 'arrowur') => `<a class="st-go" href="#go">${t}${icon ? svg(icon, 14) : ''}</a>`;
export const ring = (tone = 'neutral') => `<span class="st-ring ${tone}" aria-hidden="true"></span>`;

// a switch that shows its On/Off word (Fleet OnOff: switch, then the word)
export const sw = (on, o = {}) => `<span class="oc-switch${on && !o.dis ? '' : ' off'}"><span class="oc-sw${on ? '' : ' off'}${o.dis ? ' dis' : ''}${o.foc ? ' foc' : ''}" role="switch" aria-checked="${on}"${o.dis ? ' aria-disabled="true"' : ''}></span>${on ? 'On' : 'Off'}</span>`;
// segmented control (three or more real options, or two named modes)
export const seg = (opts, on, o = {}) => `<span class="oc-tgl${o.dis ? ' st-dis' : ''}" role="radiogroup">${opts.map((t) => `<span role="radio" aria-checked="${t === on}"${t === on ? ' class="on"' : ''}>${t}</span>`).join('')}</span>`;
// a whole number with its unit after it
export const num = (v, unit, o = {}) => `<span class="st-num"><span class="oc-input${o.dis ? ' dis' : ''}${o.err ? ' err' : ''}${o.focus ? ' focus' : ''}">${v}</span><span class="oc-muted">${unit}</span></span>`;
// date and time pickers (the approved pickers, never a browser box)
export const tpick = (t, sub = 'NZ time', o = {}) => `<button class="oc-dt st-dt${o.dis ? ' dis' : ''}${o.open ? ' open' : ''}${o.empty ? ' empty' : ''}" type="button"><span class="ti">${svg('clock', 15)}</span><span><b>${t}</b>${sub ? `<small>${sub}</small>` : ''}</span>${svg('chev', 14, 'chev')}</button>`;
export const dpick = (t, sub = '', o = {}) => `<button class="oc-dt st-dt${o.dis ? ' dis' : ''}${o.empty ? ' empty' : ''}" type="button"><span class="ti">${svg('cal', 15)}</span><span><b>${t}</b>${sub ? `<small>${sub}</small>` : ''}</span>${svg('chev', 14, 'chev')}</button>`;
export const xchip = (t) => `<span class="oc-xchip">${t}<button type="button" aria-label="Remove ${t}">${svg('x', 12)}</button></span>`;

// one setting: label + badges + one-line hint left, control right, choices below
export function row({ label, hint = '', ctrl = '', badges = '', sub = '', state = '', id = '' }) {
    return `<div class="oc-setline st-row${state === 'changed' ? ' changed' : ''}${state === 'dis' ? ' st-off' : ''}"${id ? ` id="${id}"` : ''}><div class="st-l"><b>${label}${badges}</b>${hint ? `<small>${hint}</small>` : ''}</div>${ctrl ? `<div class="r">${ctrl}</div>` : ''}${sub ? `<div class="st-sub">${sub}</div>` : ''}</div>`;
}
export const changed = () => badge('Changed — not saved', 'info');
export const cant = () => badge('Can’t switch on yet', 'neutral');
export const siteOnly = (site = 'Kōwhai House') => badge(`${site} only`, 'neutral');
export const loosens = () => `<span class="st-loose">${svg('alert', 13)}Loosens a safety check</span>`;
export const follows = (v) => `<span class="st-inh">${svg('link', 12)}Follows the organisation${v ? ` · ${v}` : ''}</span>`;

// a titled group of compact rows (eMAR SettingGroup)
export const group = ({ icon, title, cap: c = '', rows, wide = false, style = '' }) =>
    `<section class="oc-card st-grp${wide ? ' wide' : ''}"${style ? ` style="${style}"` : ''}><div class="oc-card-h">${lens(icon)}<div style="min-width: 0"><div class="oc-card-t st-gt">${title}</div>${c ? `<div class="oc-card-s">${c}</div>` : ''}</div></div>${rows.join('')}</section>`;
export const gg = (...groups) => `<div class="st-gg">${groups.join('')}</div>`;
export const gcol = (...groups) => `<div class="st-gcol">${groups.join('')}</div>`;

// list caption (ListCaption): title, caption, something on the right
export const lc = (title, caption = '', right = '') => `<div class="st-lc"><b>${title}</b>${caption ? `<span>${caption}</span>` : ''}${right ? `<span class="st-lcr">${right}</span>` : ''}</div>`;

// the Tally block: related numbers on one line between hairlines; each label carries its state ring
export const tally = (items, calm = '') => `<div class="oc-card st-tally">${items.map(([v, label, tone, sub]) => `<a class="st-t" href="#t"><b>${v}</b><span>${ring(tone)}${label}</span>${sub ? `<small>${sub}</small>` : ''}</a>`).join('')}${calm ? `<p class="st-calm">${ring('ok')}${calm}</p>` : ''}</div>`;

// overview review card: icon + title, lines, optional badge or rows, "Review … ↗"
export function rv({ icon, title, lines = [], badgeH = '', rows = '', cta, wide = false, extra = '' }) {
    return `<section class="oc-rvc st-rv${wide ? ' wide' : ''}"><div class="oc-rvc-h">${svg(icon, 15)}${title}${badgeH ? `<span class="st-rvh">${badgeH}</span>` : ''}</div><div class="st-rvb">${lines.map((l) => `<p>${l}</p>`).join('')}${rows}${extra}${cta ? link(cta) : ''}</div></section>`;
}
export const rvr = (l, v) => `<div class="oc-rvr"><span>${l}</span><span>${v}</span></div>`;
export const chg = (before, after, extra = '') => `<span class="st-chg"><s>${before}</s> → <b>${after}</b>${extra}</span>`;

// sticky save bar (Fleet / eMAR SaveBar)
export function savebar({ count = 0, title = '', sub = '', actions = '', dot = true, loose = '' }) {
    const t = title || (count ? `${count} ${count === 1 ? 'change' : 'changes'} to review` : 'No unsaved changes');
    const s = sub || (count ? 'Nothing applies until you review and save. Your changes stay while you move between tabs and views.' : 'Changes apply only after you review and save them.');
    const a = actions || `${btn('Discard changes', 'o', { sm: true, dis: !count })}${btn('Review changes', 'p', { sm: true, dis: !count })}`;
    return `<div class="oc-savebar st-save" role="region" aria-label="Save changes">${count && dot ? '<span class="dot" aria-hidden="true"></span>' : ''}<div class="st-savet"><b>${t}</b>${loose ? `<span class="st-loose">${svg('alert', 13)}${loose}</span>` : ''}<small>${s}</small></div><div class="st-savea">${a}</div></div>`;
}

// dialogs (Fleet Modal layout: fixed head, scrolling body, footer)
export function dlg({ size = 'sm', icon, tone = '', title, sub = '', body, foot = '', style = '' }) {
    return `<section class="oc-dlg ${size}" role="dialog" aria-label="${title.replace(/<[^>]+>/g, '').replace(/"/g, '&quot;')}"${style ? ` style="${style}"` : ''}>
<div class="oc-mh">${icon ? `<span class="ic${tone ? ' ' + tone : ''}">${svg(icon, 20)}</span>` : ''}<div><h3>${title}</h3>${sub ? `<p>${sub}</p>` : ''}</div><button class="oc-x" type="button" aria-label="Close">${svg('x', 18)}</button></div>
<div class="oc-mb">${body}</div>
${foot ? `<div class="oc-mf">${foot}</div>` : ''}
</section>`;
}

// ---------- the Event Horizon page header (compact for settings; full for a record) ----------
export function sky({ icon = 'chat', markAv = '', title, chip = '', status = '', sub, actions = '', filters = '', meters = '', tabs = [], on = 0, find = true, back = false }) {
    const mark = markAv ? `<span class="oc-mark av" style="width: 40px; height: 40px; font-size: 14px" aria-hidden="true">${markAv}</span>` : `<span class="oc-mark" style="width: 40px; height: 40px" aria-hidden="true">${svg(icon, 18)}</span>`;
    const tab = (t, i) => {
        const [x, n] = Array.isArray(t) ? t : [t];
        return `<a class="oc-tab${i === on ? ' on' : ''}" href="#tab"${i === on ? ' aria-current="page"' : ''}>${x}${n ? `<span class="n">${n}</span>` : ''}</a>`;
    };
    return `<header class="oc-sky st-sky">
<div class="oc-sky-top">${back ? `<span class="oc-back" aria-label="Back to Chat investigations">${svg('chevl', 16)}</span>` : ''}${mark}<div style="min-width: 0"><div class="oc-title" style="font-size: 20px">${title}${chip ? `<span class="oc-skychip">${chip}</span>` : ''}${status}</div><div class="oc-sub-line">${sub}</div></div>${actions ? `<div class="oc-sky-actions">${actions}</div>` : ''}</div>
${meters ? `<div class="oc-meters">${meters}</div>` : ''}${filters ? `<div class="oc-filters">${filters}</div>` : ''}
<nav class="oc-rail" aria-label="Views">${tabs.map(tab).join('')}${find ? `<a class="oc-tab find" href="#find">${svg('search', 14)}Find</a>` : ''}</nav>
</header>`;
}
export const gbtn = (label, icon = '', after = '') => `<button class="oc-gbtn" type="button">${icon ? svg(icon, 15) : ''}${label}${after ? svg(after, 14) : ''}</button>`;
export const wbtn = (label, icon = '') => `<button class="oc-wbtn" type="button">${icon ? svg(icon, 15) : ''}${label}</button>`;
export const fchip = (label, on = false) => `<button class="oc-fchip${on ? ' on' : ''}" type="button" aria-haspopup="listbox">${label}${svg('chev', 13)}</button>`;
export const meter = (h, v, c = '', tone = '') => `<a class="oc-meter${tone ? ' ' + tone : ''}" href="#m"><span class="h">${h}</span><span class="v" style="font-size: 18px">${v}</span>${c ? `<span class="c">${c}</span>` : ''}</a>`;

// tier-2 sub-tabs (TierTwoTabs): neutral at rest; the active tab takes its positional tone
export const t2 = (tabs, on = 0) => `<nav class="oc-t2 st-t2" aria-label="Sections">${tabs.map(([label, icon, n], i) => `<a href="#s"${i === on ? ` class="on tn${i % 5}" aria-current="page"` : ''}><span class="c">${svg(icon, 12)}</span>${label}${n ? `<span class="n">${n}</span>` : ''}</a>`).join('')}</nav>`;

// the settings menu (SettingsLayout, 220 px)
export function settingsMenu(active, { investigator = false } = {}) {
    const g = (caption, items) => `<div class="cs-sm-cap">${caption}</div><div class="cs-sm-g">${items.map(([icon, t, b]) => `<a class="cs-sm-i${t === active ? ' on' : ''}" href="#settings"${t === active ? ' aria-current="page"' : ''}>${svg(icon, 15)}${t}${b ? `<span class="oc-badge info sm nodot">${b}</span>` : ''}</a>`).join('')}</div>`;
    return `<nav class="cs-sm" aria-label="Settings">
<div class="cs-sm-h">${lens('sliders')}<b>Settings</b></div>
<div class="cs-sm-find">${svg('search', 14)}Find a setting<span class="oc-kbd">/</span></div>
${g('General', [['user', 'Profile'], ['palette', 'Appearance'], ['lock', 'Password'], ['shieldcheck', 'Two-factor authentication'], ['key', 'Witness PIN'], ['chattext', 'My Chat preferences', 'New']])}
${g('Chat', [['chattext', 'Chat settings'], ...(investigator ? [['shieldalert', 'Chat investigations']] : [])])}
${g('Organisation', [['paint', 'Branding'], ['building', 'Service contexts'], ['boxes', 'Modules and features']])}
${g('People and access', [['users', 'Users'], ['shield', 'Roles'], ['key', 'Single sign-on']])}
${g('Notifications', [['bell', 'My notifications'], ['users', 'Role defaults'], ['alert', 'Escalation rules'], ['mail', 'Email']])}
${g('Data and compliance', [['database', 'Data and privacy'], ['history', 'Audit logs']])}
</nav>`;
}

// ---------- page and board writer ----------
const TOP = readFileSync(join(HERE, 'top.html'), 'utf8');
const SIDE = readFileSync(join(HERE, 'side.html'), 'utf8');
let sceneN = 0;
export function top(you = 'DA', youName = 'Demo Admin') {
    return TOP.replace('<b>Monday</b> 19 October 2026', '<b>Friday</b> 30 October 2026')
        .replace(/<span class="oc-avatar" aria-label="Your account: [^"]*">[A-Z]+<\/span>/, `<span class="oc-avatar" aria-label="Your account: ${youName}">${you}</span>`);
}
export function side(active = 'settings') {
    sceneN += 1;
    let s = SIDE.replace(/isl-side-c1/g, `isl-side-s${sceneN}`)
        .replace('<a class="oc-nav on" href="#chat" aria-current="page">', '<a class="oc-nav" href="#chat">');
    if (active === 'settings') s = s.replace('<a class="oc-nav" href="#settings" style="margin-top: 6px">', '<a class="oc-nav on" href="#settings" aria-current="page" style="margin-top: 6px">');
    return s;
}
export const crumbs = (...c) => `<div class="oc-crumbs">${c.map((x, i) => (i === c.length - 1 ? `<b>${x}</b>` : `<span>${x}</span><span aria-hidden="true">›</span>`)).join('')}</div>`;
export const scene = (inner, { crumbsT = ['Home', 'Settings', 'Chat'], h = 1000, you = 'DA', youName = 'Demo Admin' } = {}) =>
    `<div class="ch-frame" style="width: 1440px"><div class="oc oc-app pp-board t-d l b-journey lg-journey" style="height: ${h}px">
${top(you, youName)}
${side('settings')}
<main class="oc-main">
${crumbs(...crumbsT)}
${inner}
</main>
</div></div>`;
// a settings page: 220 px settings menu, then the page column (header, sub-tabs, body, save bar)
export const settingsPage = ({ menu, header, t2: sub = '', body, save = '' }) =>
    `<div class="cs-wrap st-wrap">${menu}<div class="st-page">${header}${sub}<div class="st-body">${body}</div>${save}</div></div>`;
// body-only crop at the page column's real width (1440 − 236 nav − 40 gutter − 220 menu − 20 gap = 924)
export const crop = (inner, { h = 0, w = 924 } = {}) => `<div class="ch-frame st-crop" style="width: ${w}px"><div class="oc pp-board t-d l b-journey lg-journey st-cropin"${h ? ` style="height: ${h}px"` : ''}>${inner}</div></div>`;
// a loose part on its own ground (dialogs, bars)
export const ground = (inner, style = '') => `<div class="oc pp-board t-d l b-journey lg-journey st-ground"${style ? ` style="${style}"` : ''}>${inner}</div>`;

const MODE = `<script>(function(){function m(){var d=location.hash==='#d';document.querySelectorAll('.oc.pp-board').forEach(function(e){e.classList.toggle('d',d);e.classList.toggle('l',!d);});document.querySelectorAll('.pp-head .mode').forEach(function(e){e.textContent=d?'Dark':'Light';});}m();addEventListener('hashchange',m);})();</script>`;
const LOCAL = readFileSync(join(HERE, 'local.css'), 'utf8');
export function writeBoard(file, title, h1, desc, sections) {
    const body = `<div class="oc oc-kit pp-board t-d l b-journey lg-journey" style="width: 1600px; padding-left: 40px; padding-right: 40px">
<div class="oc-kit-h pp-head"><h1>${h1}</h1><p>${desc}</p><span class="mode">Light</span></div>
${sections.join('\n')}
</div>`;
    const html = `<!doctype html>
<html lang="en-NZ">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title}</title>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="./oc.css">
<link rel="stylesheet" href="./oc-chat.css">
<link rel="stylesheet" href="./oc-chat3.css">
<style>html,body{margin:0;background:transparent}
${LOCAL}</style>
</head>
<body>
${body}
${MODE}
</body>
</html>
`;
    if (/�/.test(html)) throw new Error('U+FFFD in ' + file);
    const hex = (LOCAL.match(/#[0-9a-fA-F]{3,8}\b/g) || []);
    if (hex.length) throw new Error('raw hex in local.css: ' + hex.join(', '));
    writeFileSync(join(OUT, file), html);
    return html.length;
}
