import {
    AirVent,
    Archive,
    Armchair,
    Battery,
    Cable,
    Camera,
    FireExtinguisher,
    HardDrive,
    Headphones,
    Image,
    Keyboard,
    Laptop,
    Library,
    Monitor,
    Mouse,
    Package,
    Printer,
    Projector,
    Router,
    Scan,
    Server,
    Smartphone,
    Speaker,
    Table,
    Tablet,
    Vault,
    Watch,
    type LucideIcon,
} from 'lucide-react';

/**
 * The drawings a category of hardware can be shown by, keyed as the category
 * stores them. The list is kept beside the server's own (AppSupportEquipmentIcons)
 * and has to hold the same keys: the directory offers what the server accepts,
 * and the interface draws what it stored.
 */
export const equipmentIcons: Record<string, LucideIcon> = {
    laptop: Laptop,
    monitor: Monitor,
    smartphone: Smartphone,
    tablet: Tablet,
    keyboard: Keyboard,
    mouse: Mouse,
    headphones: Headphones,
    printer: Printer,
    scanner: Scan,
    projector: Projector,
    camera: Camera,
    server: Server,
    harddrive: HardDrive,
    router: Router,
    speaker: Speaker,
    watch: Watch,
    cable: Cable,
    battery: Battery,
    chair: Armchair,
    // The things a company owns that are not computers: what people sit at and
    // keep their things in, what hangs on the wall, and the two every office is
    // inspected for.
    desk: Table,
    shelves: Library,
    cabinet: Archive,
    picture: Image,
    conditioner: AirVent,
    extinguisher: FireExtinguisher,
    // What a department keeps its papers and its money in.
    safe: Vault,
    package: Package,
};

/**
 * What each drawing is called, for the hover and for whoever cannot see it. A
 * grid of pictures shows how they look and says nothing about what they stand
 * for, and «Иконка picture» read out of the stored key helps nobody.
 */
export const equipmentIconNames: Record<string, string> = {
    laptop: 'Ноутбук',
    monitor: 'Монитор',
    smartphone: 'Смартфон',
    tablet: 'Планшет',
    keyboard: 'Клавиатура',
    mouse: 'Мышь',
    headphones: 'Наушники',
    printer: 'Принтер',
    scanner: 'Сканер',
    projector: 'Проектор',
    camera: 'Камера',
    server: 'Сервер',
    harddrive: 'Жёсткий диск',
    router: 'Роутер',
    speaker: 'Колонка',
    watch: 'Часы',
    cable: 'Кабель',
    battery: 'Аккумулятор',
    chair: 'Кресло',
    desk: 'Стол',
    shelves: 'Полки',
    cabinet: 'Шкаф',
    picture: 'Картина',
    conditioner: 'Кондиционер',
    extinguisher: 'Огнетушитель',
    safe: 'Сейф',
    package: 'Коробка',
};
/** What a category without a drawing of its own is shown by. */
export const fallbackIcon = Package;
