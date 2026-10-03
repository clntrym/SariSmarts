<?php

/*
|--------------------------------------------------------------------------
| PHILIPPINE PROVINCES AND THEIR CITIES / MUNICIPALITIES
|--------------------------------------------------------------------------
|
| Used by platform/register.php for the Province and City fields -- both to
| fill the pickers and to check what was submitted.
|
| Metro Manila is not a province, but a business in Manila still has to pick
| something, so it is listed alongside them.
|
| The province list is the settled set of 81 and is checked strictly on
| submit. The city lists are a convenience: the field accepts a typed value
| that is not on its list, because an omission here must never be the reason
| a real business cannot register.
|
*/

return [

    /* ---------------------------------------------------------- NCR */
    'Metro Manila' => [
        'Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong', 'Manila',
        'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque', 'Pasay', 'Pasig',
        'Pateros', 'Quezon City', 'San Juan', 'Taguig', 'Valenzuela',
    ],

    /* ------------------------------------------------- CAR (Cordillera) */
    'Abra' => [
        'Bangued', 'Boliney', 'Bucay', 'Bucloc', 'Daguioman', 'Danglas',
        'Dolores', 'La Paz', 'Lacub', 'Lagangilang', 'Lagayan', 'Langiden',
        'Licuan-Baay', 'Luba', 'Malibcong', 'Manabo', 'Peñarrubia', 'Pidigan',
        'Pilar', 'Sallapadan', 'San Isidro', 'San Juan', 'San Quintin',
        'Tayum', 'Tineg', 'Tubo', 'Villaviciosa',
    ],
    'Apayao' => [
        'Calanasan', 'Conner', 'Flora', 'Kabugao', 'Luna', 'Pudtol', 'Santa Marcela',
    ],
    'Benguet' => [
        'Atok', 'Baguio', 'Bakun', 'Bokod', 'Buguias', 'Itogon', 'Kabayan',
        'Kapangan', 'Kibungan', 'La Trinidad', 'Mankayan', 'Sablan',
        'Tuba', 'Tublay',
    ],
    'Ifugao' => [
        'Aguinaldo', 'Alfonso Lista', 'Asipulo', 'Banaue', 'Hingyon', 'Hungduan',
        'Kiangan', 'Lagawe', 'Lamut', 'Mayoyao', 'Tinoc',
    ],
    'Kalinga' => [
        'Balbalan', 'Lubuagan', 'Pasil', 'Pinukpuk', 'Rizal', 'Tabuk',
        'Tanudan', 'Tinglayan',
    ],
    'Mountain Province' => [
        'Barlig', 'Bauko', 'Besao', 'Bontoc', 'Natonin', 'Paracelis',
        'Sabangan', 'Sadanga', 'Sagada', 'Tadian',
    ],

    /* ------------------------------------------------- Region I (Ilocos) */
    'Ilocos Norte' => [
        'Adams', 'Bacarra', 'Badoc', 'Bangui', 'Banna', 'Batac', 'Burgos',
        'Carasi', 'Currimao', 'Dingras', 'Dumalneg', 'Laoag', 'Marcos',
        'Nueva Era', 'Pagudpud', 'Paoay', 'Pasuquin', 'Piddig', 'Pinili',
        'San Nicolas', 'Sarrat', 'Solsona', 'Vintar',
    ],
    'Ilocos Sur' => [
        'Alilem', 'Banayoyo', 'Bantay', 'Burgos', 'Cabugao', 'Candon',
        'Caoayan', 'Cervantes', 'Galimuyod', 'Gregorio del Pilar', 'Lidlidda',
        'Magsingal', 'Nagbukel', 'Narvacan', 'Quirino', 'Salcedo', 'San Emilio',
        'San Esteban', 'San Ildefonso', 'San Juan', 'San Vicente', 'Santa',
        'Santa Catalina', 'Santa Cruz', 'Santa Lucia', 'Santa Maria',
        'Santiago', 'Santo Domingo', 'Sigay', 'Sinait', 'Sugpon', 'Suyo',
        'Tagudin', 'Vigan',
    ],
    'La Union' => [
        'Agoo', 'Aringay', 'Bacnotan', 'Bagulin', 'Balaoan', 'Bangar',
        'Bauang', 'Burgos', 'Caba', 'Luna', 'Naguilian', 'Pugo', 'Rosario',
        'San Fernando', 'San Gabriel', 'San Juan', 'Santo Tomas', 'Santol',
        'Sudipen', 'Tubao',
    ],
    'Pangasinan' => [
        'Agno', 'Aguilar', 'Alaminos', 'Alcala', 'Anda', 'Asingan', 'Balungao',
        'Bani', 'Basista', 'Bautista', 'Bayambang', 'Binalonan', 'Binmaley',
        'Bolinao', 'Bugallon', 'Burgos', 'Calasiao', 'Dagupan', 'Dasol',
        'Infanta', 'Labrador', 'Laoac', 'Lingayen', 'Mabini', 'Malasiqui',
        'Manaoag', 'Mangaldan', 'Mangatarem', 'Mapandan', 'Natividad',
        'Pozorrubio', 'Rosales', 'San Carlos', 'San Fabian', 'San Jacinto',
        'San Manuel', 'San Nicolas', 'San Quintin', 'Santa Barbara',
        'Santa Maria', 'Santo Tomas', 'Sison', 'Sual', 'Tayug', 'Umingan',
        'Urbiztondo', 'Urdaneta', 'Villasis',
    ],

    /* ---------------------------------------------- Region II (Cagayan Valley) */
    'Batanes' => [
        'Basco', 'Itbayat', 'Ivana', 'Mahatao', 'Sabtang', 'Uyugan',
    ],
    'Cagayan' => [
        'Abulug', 'Alcala', 'Allacapan', 'Amulung', 'Aparri', 'Baggao',
        'Ballesteros', 'Buguey', 'Calayan', 'Camalaniugan', 'Claveria',
        'Enrile', 'Gattaran', 'Gonzaga', 'Iguig', 'Lal-lo', 'Lasam',
        'Pamplona', 'Peñablanca', 'Piat', 'Rizal', 'Sanchez-Mira',
        'Santa Ana', 'Santa Praxedes', 'Santa Teresita', 'Santo Niño',
        'Solana', 'Tuao', 'Tuguegarao',
    ],
    'Isabela' => [
        'Alicia', 'Angadanan', 'Aurora', 'Benito Soliven', 'Burgos', 'Cabagan',
        'Cabatuan', 'Cauayan', 'Cordon', 'Delfin Albano', 'Dinapigue',
        'Divilacan', 'Echague', 'Gamu', 'Ilagan', 'Jones', 'Luna', 'Maconacon',
        'Mallig', 'Naguilian', 'Palanan', 'Quezon', 'Quirino', 'Ramon',
        'Reina Mercedes', 'Roxas', 'San Agustin', 'San Guillermo',
        'San Isidro', 'San Manuel', 'San Mariano', 'San Mateo', 'San Pablo',
        'Santa Maria', 'Santiago', 'Santo Tomas', 'Tumauini',
    ],
    'Nueva Vizcaya' => [
        'Alfonso Castaneda', 'Ambaguio', 'Aritao', 'Bagabag', 'Bambang',
        'Bayombong', 'Diadi', 'Dupax del Norte', 'Dupax del Sur', 'Kasibu',
        'Kayapa', 'Quezon', 'Santa Fe', 'Solano', 'Villaverde',
    ],
    'Quirino' => [
        'Aglipay', 'Cabarroguis', 'Diffun', 'Maddela', 'Nagtipunan', 'Saguday',
    ],

    /* ---------------------------------------------- Region III (Central Luzon) */
    'Aurora' => [
        'Baler', 'Casiguran', 'Dilasag', 'Dinalungan', 'Dingalan', 'Dipaculao',
        'Maria Aurora', 'San Luis',
    ],
    'Bataan' => [
        'Abucay', 'Bagac', 'Balanga', 'Dinalupihan', 'Hermosa', 'Limay',
        'Mariveles', 'Morong', 'Orani', 'Orion', 'Pilar', 'Samal',
    ],
    'Bulacan' => [
        'Angat', 'Balagtas', 'Baliuag', 'Bocaue', 'Bulakan', 'Bustos',
        'Calumpit', 'Doña Remedios Trinidad', 'Guiguinto', 'Hagonoy',
        'Malolos', 'Marilao', 'Meycauayan', 'Norzagaray', 'Obando',
        'Pandi', 'Paombong', 'Plaridel', 'Pulilan', 'San Ildefonso',
        'San Jose del Monte', 'San Miguel', 'San Rafael', 'Santa Maria',
    ],
    'Nueva Ecija' => [
        'Aliaga', 'Bongabon', 'Cabanatuan', 'Cabiao', 'Carranglan', 'Cuyapo',
        'Gabaldon', 'Gapan', 'General Mamerto Natividad', 'General Tinio',
        'Guimba', 'Jaen', 'Laur', 'Licab', 'Llanera', 'Lupao', 'Muñoz',
        'Nampicuan', 'Palayan', 'Pantabangan', 'Peñaranda', 'Quezon',
        'Rizal', 'San Antonio', 'San Isidro', 'San Jose', 'San Leonardo',
        'Santa Rosa', 'Santo Domingo', 'Talavera', 'Talugtug', 'Zaragoza',
    ],
    'Pampanga' => [
        'Angeles', 'Apalit', 'Arayat', 'Bacolor', 'Candaba', 'Floridablanca',
        'Guagua', 'Lubao', 'Mabalacat', 'Macabebe', 'Magalang', 'Masantol',
        'Mexico', 'Minalin', 'Porac', 'San Fernando', 'San Luis', 'San Simon',
        'Santa Ana', 'Santa Rita', 'Santo Tomas', 'Sasmuan',
    ],
    'Tarlac' => [
        'Anao', 'Bamban', 'Camiling', 'Capas', 'Concepcion', 'Gerona',
        'La Paz', 'Mayantoc', 'Moncada', 'Paniqui', 'Pura', 'Ramos',
        'San Clemente', 'San Jose', 'San Manuel', 'Santa Ignacia',
        'Tarlac City', 'Victoria',
    ],
    'Zambales' => [
        'Botolan', 'Cabangan', 'Candelaria', 'Castillejos', 'Iba', 'Masinloc',
        'Olongapo', 'Palauig', 'San Antonio', 'San Felipe', 'San Marcelino',
        'San Narciso', 'Santa Cruz', 'Subic',
    ],

    /* ---------------------------------------------- Region IV-A (CALABARZON) */
    'Batangas' => [
        'Agoncillo', 'Alitagtag', 'Balayan', 'Balete', 'Batangas City',
        'Bauan', 'Calaca', 'Calatagan', 'Cuenca', 'Ibaan', 'Laurel',
        'Lemery', 'Lian', 'Lipa', 'Lobo', 'Mabini', 'Malvar', 'Mataasnakahoy',
        'Nasugbu', 'Padre Garcia', 'Rosario', 'San Jose', 'San Juan',
        'San Luis', 'San Nicolas', 'San Pascual', 'Santa Teresita',
        'Santo Tomas', 'Taal', 'Talisay', 'Tanauan', 'Taysan', 'Tingloy',
        'Tuy',
    ],
    'Cavite' => [
        'Alfonso', 'Amadeo', 'Bacoor', 'Carmona', 'Cavite City', 'Dasmariñas',
        'General Emilio Aguinaldo', 'General Mariano Alvarez', 'General Trias',
        'Imus', 'Indang', 'Kawit', 'Magallanes', 'Maragondon', 'Mendez',
        'Naic', 'Noveleta', 'Rosario', 'Silang', 'Tagaytay', 'Tanza',
        'Ternate', 'Trece Martires',
    ],
    'Laguna' => [
        'Alaminos', 'Bay', 'Biñan', 'Cabuyao', 'Calamba', 'Calauan',
        'Cavinti', 'Famy', 'Kalayaan', 'Liliw', 'Los Baños', 'Luisiana',
        'Lumban', 'Mabitac', 'Magdalena', 'Majayjay', 'Nagcarlan', 'Paete',
        'Pagsanjan', 'Pakil', 'Pangil', 'Pila', 'Rizal', 'San Pablo',
        'San Pedro', 'Santa Cruz', 'Santa Maria', 'Santa Rosa', 'Siniloan',
        'Victoria',
    ],
    'Quezon' => [
        'Agdangan', 'Alabat', 'Atimonan', 'Buenavista', 'Burdeos', 'Calauag',
        'Candelaria', 'Catanauan', 'Dolores', 'General Luna', 'General Nakar',
        'Guinayangan', 'Gumaca', 'Infanta', 'Jomalig', 'Lopez', 'Lucban',
        'Lucena', 'Macalelon', 'Mauban', 'Mulanay', 'Padre Burgos', 'Pagbilao',
        'Panukulan', 'Patnanungan', 'Perez', 'Pitogo', 'Plaridel', 'Polillo',
        'Quezon', 'Real', 'Sampaloc', 'San Andres', 'San Antonio',
        'San Francisco', 'San Narciso', 'Sariaya', 'Tagkawayan', 'Tayabas',
        'Tiaong', 'Unisan',
    ],
    'Rizal' => [
        'Angono', 'Antipolo', 'Baras', 'Binangonan', 'Cainta', 'Cardona',
        'Jala-Jala', 'Morong', 'Pililla', 'Rodriguez', 'San Mateo',
        'Tanay', 'Taytay', 'Teresa',
    ],

    /* ---------------------------------------------- MIMAROPA */
    'Marinduque' => [
        'Boac', 'Buenavista', 'Gasan', 'Mogpog', 'Santa Cruz', 'Torrijos',
    ],
    'Occidental Mindoro' => [
        'Abra de Ilog', 'Calintaan', 'Looc', 'Lubang', 'Magsaysay', 'Mamburao',
        'Paluan', 'Rizal', 'Sablayan', 'San Jose', 'Santa Cruz',
    ],
    'Oriental Mindoro' => [
        'Baco', 'Bansud', 'Bongabong', 'Bulalacao', 'Calapan', 'Gloria',
        'Mansalay', 'Naujan', 'Pinamalayan', 'Pola', 'Puerto Galera',
        'Roxas', 'San Teodoro', 'Socorro', 'Victoria',
    ],
    'Palawan' => [
        'Aborlan', 'Agutaya', 'Araceli', 'Balabac', 'Bataraza', 'Brooke\'s Point',
        'Busuanga', 'Cagayancillo', 'Coron', 'Culion', 'Cuyo', 'Dumaran',
        'El Nido', 'Kalayaan', 'Linapacan', 'Magsaysay', 'Narra',
        'Puerto Princesa', 'Quezon', 'Rizal', 'Roxas', 'San Vicente',
        'Sofronio Española', 'Taytay',
    ],
    'Romblon' => [
        'Alcantara', 'Banton', 'Cajidiocan', 'Calatrava', 'Concepcion',
        'Corcuera', 'Ferrol', 'Looc', 'Magdiwang', 'Odiongan', 'Romblon',
        'San Agustin', 'San Andres', 'San Fernando', 'San Jose', 'Santa Fe',
        'Santa Maria',
    ],

    /* ---------------------------------------------- Region V (Bicol) */
    'Albay' => [
        'Bacacay', 'Camalig', 'Daraga', 'Guinobatan', 'Jovellar', 'Legazpi',
        'Libon', 'Ligao', 'Malilipot', 'Malinao', 'Manito', 'Oas', 'Pio Duran',
        'Polangui', 'Rapu-Rapu', 'Santo Domingo', 'Tabaco', 'Tiwi',
    ],
    'Camarines Norte' => [
        'Basud', 'Capalonga', 'Daet', 'Jose Panganiban', 'Labo', 'Mercedes',
        'Paracale', 'San Lorenzo Ruiz', 'San Vicente', 'Santa Elena',
        'Talisay', 'Vinzons',
    ],
    'Camarines Sur' => [
        'Baao', 'Balatan', 'Bato', 'Bombon', 'Buhi', 'Bula', 'Cabusao',
        'Calabanga', 'Camaligan', 'Canaman', 'Caramoan', 'Del Gallego',
        'Gainza', 'Garchitorena', 'Goa', 'Iriga', 'Lagonoy', 'Libmanan',
        'Lupi', 'Magarao', 'Milaor', 'Minalabac', 'Nabua', 'Naga', 'Ocampo',
        'Pamplona', 'Pasacao', 'Pili', 'Presentacion', 'Ragay', 'Sagñay',
        'San Fernando', 'San Jose', 'Sipocot', 'Siruma', 'Tigaon', 'Tinambac',
    ],
    'Catanduanes' => [
        'Bagamanoc', 'Baras', 'Bato', 'Caramoran', 'Gigmoto', 'Pandan',
        'Panganiban', 'San Andres', 'San Miguel', 'Viga', 'Virac',
    ],
    'Masbate' => [
        'Aroroy', 'Baleno', 'Balud', 'Batuan', 'Cataingan', 'Cawayan',
        'Claveria', 'Dimasalang', 'Esperanza', 'Mandaon', 'Masbate City',
        'Milagros', 'Mobo', 'Monreal', 'Palanas', 'Pio V. Corpuz', 'Placer',
        'San Fernando', 'San Jacinto', 'San Pascual', 'Uson',
    ],
    'Sorsogon' => [
        'Barcelona', 'Bulan', 'Bulusan', 'Casiguran', 'Castilla', 'Donsol',
        'Gubat', 'Irosin', 'Juban', 'Magallanes', 'Matnog', 'Pilar',
        'Prieto Diaz', 'Santa Magdalena', 'Sorsogon City',
    ],

    /* ---------------------------------------------- Region VI (Western Visayas) */
    'Aklan' => [
        'Altavas', 'Balete', 'Banga', 'Batan', 'Buruanga', 'Ibajay', 'Kalibo',
        'Lezo', 'Libacao', 'Madalag', 'Makato', 'Malay', 'Malinao', 'Nabas',
        'New Washington', 'Numancia', 'Tangalan',
    ],
    'Antique' => [
        'Anini-y', 'Barbaza', 'Belison', 'Bugasong', 'Caluya', 'Culasi',
        'Hamtic', 'Laua-an', 'Libertad', 'Pandan', 'Patnongon',
        'San Jose de Buenavista', 'San Remigio', 'Sebaste', 'Sibalom',
        'Tibiao', 'Tobias Fornier', 'Valderrama',
    ],
    'Capiz' => [
        'Cuartero', 'Dao', 'Dumalag', 'Dumarao', 'Ivisan', 'Jamindan',
        'Maayon', 'Mambusao', 'Panay', 'Panitan', 'Pilar', 'Pontevedra',
        'President Roxas', 'Roxas City', 'Sapian', 'Sigma', 'Tapaz',
    ],
    'Guimaras' => [
        'Buenavista', 'Jordan', 'Nueva Valencia', 'San Lorenzo', 'Sibunag',
    ],
    'Iloilo' => [
        'Ajuy', 'Alimodian', 'Anilao', 'Badiangan', 'Balasan', 'Banate',
        'Barotac Nuevo', 'Barotac Viejo', 'Batad', 'Bingawan', 'Cabatuan',
        'Calinog', 'Carles', 'Concepcion', 'Dingle', 'Duenas', 'Dumangas',
        'Estancia', 'Guimbal', 'Igbaras', 'Iloilo City', 'Janiuay', 'Lambunao',
        'Leganes', 'Lemery', 'Leon', 'Maasin', 'Miagao', 'Mina', 'New Lucena',
        'Oton', 'Passi', 'Pavia', 'Pototan', 'San Dionisio', 'San Enrique',
        'San Joaquin', 'San Miguel', 'San Rafael', 'Santa Barbara', 'Sara',
        'Tigbauan', 'Tubungan', 'Zarraga',
    ],
    'Negros Occidental' => [
        'Bacolod', 'Bago', 'Binalbagan', 'Cadiz', 'Calatrava', 'Candoni',
        'Cauayan', 'Enrique B. Magalona', 'Escalante', 'Himamaylan',
        'Hinigaran', 'Hinoba-an', 'Ilog', 'Isabela', 'Kabankalan',
        'La Carlota', 'La Castellana', 'Manapla', 'Moises Padilla', 'Murcia',
        'Pontevedra', 'Pulupandan', 'Sagay', 'Salvador Benedicto',
        'San Carlos', 'San Enrique', 'Silay', 'Sipalay', 'Talisay', 'Toboso',
        'Valladolid', 'Victorias',
    ],

    /* ---------------------------------------------- Region VII (Central Visayas) */
    'Bohol' => [
        'Alburquerque', 'Alicia', 'Anda', 'Antequera', 'Baclayon', 'Balilihan',
        'Batuan', 'Bien Unido', 'Bilar', 'Buenavista', 'Calape', 'Candijay',
        'Carmen', 'Catigbian', 'Clarin', 'Corella', 'Cortes', 'Dagohoy',
        'Danao', 'Dauis', 'Dimiao', 'Duero', 'Garcia Hernandez', 'Getafe',
        'Guindulman', 'Inabanga', 'Jagna', 'Lila', 'Loay', 'Loboc', 'Loon',
        'Mabini', 'Maribojoc', 'Panglao', 'Pilar', 'President Carlos P. Garcia',
        'Sagbayan', 'San Isidro', 'San Miguel', 'Sevilla', 'Sierra Bullones',
        'Sikatuna', 'Tagbilaran', 'Talibon', 'Trinidad', 'Tubigon', 'Ubay',
        'Valencia',
    ],
    'Cebu' => [
        'Alcantara', 'Alcoy', 'Alegria', 'Aloguinsan', 'Argao', 'Asturias',
        'Badian', 'Balamban', 'Bantayan', 'Barili', 'Bogo', 'Boljoon',
        'Borbon', 'Carcar', 'Carmen', 'Catmon', 'Cebu City', 'Compostela',
        'Consolacion', 'Cordova', 'Daanbantayan', 'Dalaguete', 'Danao',
        'Dumanjug', 'Ginatilan', 'Lapu-Lapu', 'Liloan', 'Madridejos',
        'Malabuyoc', 'Mandaue', 'Medellin', 'Minglanilla', 'Moalboal',
        'Naga', 'Oslob', 'Pilar', 'Pinamungajan', 'Poro', 'Ronda', 'Samboan',
        'San Fernando', 'San Francisco', 'San Remigio', 'Santa Fe',
        'Santander', 'Sibonga', 'Sogod', 'Tabogon', 'Tabuelan', 'Talisay',
        'Toledo', 'Tuburan', 'Tudela',
    ],
    'Negros Oriental' => [
        'Amlan', 'Ayungon', 'Bacong', 'Bais', 'Basay', 'Bayawan', 'Bindoy',
        'Canlaon', 'Dauin', 'Dumaguete', 'Guihulngan', 'Jimalalud',
        'La Libertad', 'Mabinay', 'Manjuyod', 'Pamplona', 'San Jose',
        'Santa Catalina', 'Siaton', 'Sibulan', 'Tanjay', 'Tayasan',
        'Valencia', 'Vallehermoso', 'Zamboanguita',
    ],
    'Siquijor' => [
        'Enrique Villanueva', 'Larena', 'Lazi', 'Maria', 'San Juan', 'Siquijor',
    ],

    /* ---------------------------------------------- Region VIII (Eastern Visayas) */
    'Biliran' => [
        'Almeria', 'Biliran', 'Cabucgayan', 'Caibiran', 'Culaba', 'Kawayan',
        'Maripipi', 'Naval',
    ],
    'Eastern Samar' => [
        'Arteche', 'Balangiga', 'Balangkayan', 'Borongan', 'Can-avid',
        'Dolores', 'General MacArthur', 'Giporlos', 'Guiuan', 'Hernani',
        'Jipapad', 'Lawaan', 'Llorente', 'Maslog', 'Maydolong', 'Mercedes',
        'Oras', 'Quinapondan', 'Salcedo', 'San Julian', 'San Policarpo',
        'Sulat', 'Taft',
    ],
    'Leyte' => [
        'Abuyog', 'Alangalang', 'Albuera', 'Babatngon', 'Barugo', 'Bato',
        'Baybay', 'Burauen', 'Calubian', 'Capoocan', 'Carigara', 'Dagami',
        'Dulag', 'Hilongos', 'Hindang', 'Inopacan', 'Isabel', 'Jaro',
        'Javier', 'Julita', 'Kananga', 'La Paz', 'Leyte', 'MacArthur',
        'Mahaplag', 'Matag-ob', 'Matalom', 'Mayorga', 'Merida', 'Ormoc',
        'Palo', 'Palompon', 'Pastrana', 'San Isidro', 'San Miguel',
        'Santa Fe', 'Tabango', 'Tabontabon', 'Tacloban', 'Tanauan',
        'Tolosa', 'Tunga', 'Villaba',
    ],
    'Northern Samar' => [
        'Allen', 'Biri', 'Bobon', 'Capul', 'Catarman', 'Catubig', 'Gamay',
        'Laoang', 'Lapinig', 'Las Navas', 'Lavezares', 'Lope de Vega',
        'Mapanas', 'Mondragon', 'Palapag', 'Pambujan', 'Rosario',
        'San Antonio', 'San Isidro', 'San Jose', 'San Roque', 'San Vicente',
        'Silvino Lobos', 'Victoria',
    ],
    'Samar' => [
        'Almagro', 'Basey', 'Calbayog', 'Calbiga', 'Catbalogan', 'Daram',
        'Gandara', 'Hinabangan', 'Jiabong', 'Marabut', 'Matuguinao',
        'Motiong', 'Pagsanghan', 'Paranas', 'Pinabacdao', 'San Jorge',
        'San Jose de Buan', 'San Sebastian', 'Santa Margarita', 'Santa Rita',
        'Santo Nino', 'Tagapul-an', 'Talalora', 'Tarangnan', 'Villareal',
        'Zumarraga',
    ],
    'Southern Leyte' => [
        'Anahawan', 'Bontoc', 'Hinunangan', 'Hinundayan', 'Libagon', 'Liloan',
        'Limasawa', 'Maasin', 'Macrohon', 'Malitbog', 'Padre Burgos',
        'Pintuyan', 'Saint Bernard', 'San Francisco', 'San Juan',
        'San Ricardo', 'Silago', 'Sogod', 'Tomas Oppus',
    ],

    /* ---------------------------------------------- Region IX (Zamboanga Peninsula) */
    'Zamboanga del Norte' => [
        'Bacungan', 'Baliguian', 'Dapitan', 'Dipolog', 'Godod', 'Gutalac',
        'Jose Dalman', 'Kalawit', 'Katipunan', 'La Libertad', 'Labason',
        'Liloy', 'Manukan', 'Mutia', 'Piñan', 'Polanco', 'Pres. Manuel A. Roxas',
        'Rizal', 'Salug', 'Sergio Osmeña Sr.', 'Siayan', 'Sibuco', 'Sibutad',
        'Sindangan', 'Siocon', 'Sirawai', 'Tampilisan',
    ],
    'Zamboanga del Sur' => [
        'Aurora', 'Bayog', 'Dimataling', 'Dinas', 'Dumalinao', 'Dumingag',
        'Guipos', 'Josefina', 'Kumalarang', 'Labangan', 'Lakewood',
        'Lapuyan', 'Mahayag', 'Margosatubig', 'Midsalip', 'Molave',
        'Pagadian', 'Pitogo', 'Ramon Magsaysay', 'San Miguel', 'San Pablo',
        'Sominot', 'Tabina', 'Tambulig', 'Tigbao', 'Tukuran', 'Vincenzo A. Sagun',
        'Zamboanga City',
    ],
    'Zamboanga Sibugay' => [
        'Alicia', 'Buug', 'Diplahan', 'Imelda', 'Ipil', 'Kabasalan',
        'Mabuhay', 'Malangas', 'Naga', 'Olutanga', 'Payao', 'Roseller Lim',
        'Siay', 'Talusan', 'Titay', 'Tungawan',
    ],

    /* ---------------------------------------------- Region X (Northern Mindanao) */
    'Bukidnon' => [
        'Baungon', 'Cabanglasan', 'Damulog', 'Dangcagan', 'Don Carlos',
        'Impasug-ong', 'Kadingilan', 'Kalilangan', 'Kibawe', 'Kitaotao',
        'Lantapan', 'Libona', 'Malaybalay', 'Malitbog', 'Manolo Fortich',
        'Maramag', 'Pangantucan', 'Quezon', 'San Fernando', 'Sumilao',
        'Talakag', 'Valencia',
    ],
    'Camiguin' => [
        'Catarman', 'Guinsiliban', 'Mahinog', 'Mambajao', 'Sagay',
    ],
    'Lanao del Norte' => [
        'Bacolod', 'Baloi', 'Baroy', 'Iligan', 'Kapatagan', 'Kauswagan',
        'Kolambugan', 'Lala', 'Linamon', 'Magsaysay', 'Maigo', 'Matungao',
        'Munai', 'Nunungan', 'Pantao Ragat', 'Pantar', 'Poona Piagapo',
        'Salvador', 'Sapad', 'Sultan Naga Dimaporo', 'Tagoloan', 'Tangcal',
        'Tubod',
    ],
    'Misamis Occidental' => [
        'Aloran', 'Baliangao', 'Bonifacio', 'Calamba', 'Clarin', 'Concepcion',
        'Don Victoriano Chiongbian', 'Jimenez', 'Lopez Jaena', 'Oroquieta',
        'Ozamiz', 'Panaon', 'Plaridel', 'Sapang Dalaga', 'Sinacaban',
        'Tangub', 'Tudela',
    ],
    'Misamis Oriental' => [
        'Alubijid', 'Balingasag', 'Balingoan', 'Binuangan', 'Cagayan de Oro',
        'Claveria', 'El Salvador', 'Gingoog', 'Gitagum', 'Initao', 'Jasaan',
        'Kinoguitan', 'Lagonglong', 'Laguindingan', 'Libertad', 'Lugait',
        'Magsaysay', 'Manticao', 'Medina', 'Naawan', 'Opol', 'Salay',
        'Sugbongcogon', 'Tagoloan', 'Talisayan', 'Villanueva',
    ],

    /* ---------------------------------------------- Region XI (Davao) */
    'Davao de Oro' => [
        'Compostela', 'Laak', 'Mabini', 'Maco', 'Maragusan', 'Mawab',
        'Monkayo', 'Montevista', 'Nabunturan', 'New Bataan', 'Pantukan',
    ],
    'Davao del Norte' => [
        'Asuncion', 'Braulio E. Dujali', 'Carmen', 'Kapalong', 'New Corella',
        'Panabo', 'Samal', 'San Isidro', 'Santo Tomas', 'Tagum', 'Talaingod',
    ],
    'Davao del Sur' => [
        'Bansalan', 'Davao City', 'Digos', 'Hagonoy', 'Kiblawan', 'Magsaysay',
        'Malalag', 'Matanao', 'Padada', 'Santa Cruz', 'Sulop',
    ],
    'Davao Occidental' => [
        'Don Marcelino', 'Jose Abad Santos', 'Malita', 'Santa Maria', 'Sarangani',
    ],
    'Davao Oriental' => [
        'Baganga', 'Banaybanay', 'Boston', 'Caraga', 'Cateel', 'Governor Generoso',
        'Lupon', 'Manay', 'Mati', 'San Isidro', 'Tarragona',
    ],

    /* ---------------------------------------------- Region XII (SOCCSKSARGEN) */
    'Cotabato' => [
        'Alamada', 'Aleosan', 'Antipas', 'Arakan', 'Banisilan', 'Carmen',
        'Kabacan', 'Kidapawan', 'Libungan', 'Magpet',
        'Makilala', 'Matalam', 'Midsayap', "M'lang", 'Pigcawayan', 'Pikit',
        'President Roxas', 'Tulunan',
    ],
    'Sarangani' => [
        'Alabel', 'Glan', 'Kiamba', 'Maasim', 'Maitum', 'Malapatan', 'Malungon',
    ],
    'South Cotabato' => [
        'Banga', 'General Santos', 'Koronadal', 'Lake Sebu', 'Norala',
        'Polomolok', 'Santo Niño', 'Surallah', 'Tampakan', 'Tantangan',
        'Tboli', 'Tupi',
    ],
    'Sultan Kudarat' => [
        'Bagumbayan', 'Columbio', 'Esperanza', 'Isulan', 'Kalamansig',
        'Lambayong', 'Lebak', 'Lutayan', 'Palimbang', 'President Quirino',
        'Senator Ninoy Aquino', 'Tacurong',
    ],

    /* ---------------------------------------------- Caraga */
    'Agusan del Norte' => [
        'Buenavista', 'Butuan', 'Cabadbaran', 'Carmen', 'Jabonga', 'Kitcharao',
        'Las Nieves', 'Magallanes', 'Nasipit', 'Remedios T. Romualdez',
        'Santiago', 'Tubay',
    ],
    'Agusan del Sur' => [
        'Bayugan', 'Bunawan', 'Esperanza', 'La Paz', 'Loreto', 'Prosperidad',
        'Rosario', 'San Francisco', 'San Luis', 'Santa Josefa', 'Sibagat',
        'Talacogon', 'Trento', 'Veruela',
    ],
    'Dinagat Islands' => [
        'Basilisa', 'Cagdianao', 'Dinagat', 'Libjo', 'Loreto', 'San Jose',
        'Tubajon',
    ],
    'Surigao del Norte' => [
        'Alegria', 'Bacuag', 'Burgos', 'Claver', 'Dapa', 'Del Carmen',
        'General Luna', 'Gigaquit', 'Mainit', 'Malimono', 'Pilar',
        'Placer', 'San Benito', 'San Francisco', 'San Isidro', 'Santa Monica',
        'Sison', 'Socorro', 'Surigao City', 'Tagana-an', 'Tubod',
    ],
    'Surigao del Sur' => [
        'Barobo', 'Bayabas', 'Bislig', 'Cagwait', 'Cantilan', 'Carmen',
        'Carrascal', 'Cortes', 'Hinatuan', 'Lanuza', 'Lianga', 'Lingig',
        'Madrid', 'Marihatag', 'San Agustin', 'San Miguel', 'Tagbina',
        'Tago', 'Tandag',
    ],

    /* ---------------------------------------------- BARMM */
    'Basilan' => [
        'Akbar', 'Al-Barka', 'Hadji Mohammad Ajul', 'Hadji Muhtamad',
        'Isabela City', 'Lamitan', 'Lantawan', 'Maluso', 'Sumisip',
        'Tabuan-Lasa', 'Tipo-Tipo', 'Tuburan', 'Ungkaya Pukan',
    ],
    'Lanao del Sur' => [
        'Bacolod-Kalawi', 'Balabagan', 'Balindong', 'Bayang', 'Binidayan',
        'Buadiposo-Buntong', 'Bubong', 'Bumbaran', 'Butig', 'Calanogas',
        'Ditsaan-Ramain', 'Ganassi', 'Kapai', 'Kapatagan', 'Lumba-Bayabao',
        'Lumbaca-Unayan', 'Lumbatan', 'Lumbayanague', 'Madalum', 'Madamba',
        'Maguing', 'Malabang', 'Marantao', 'Marawi', 'Marogong', 'Masiu',
        'Mulondo', 'Pagayawan', 'Piagapo', 'Poona Bayabao', 'Pualas',
        'Saguiaran', 'Sultan Dumalondong', 'Tagoloan II', 'Tamparan',
        'Taraka', 'Tubaran', 'Tugaya', 'Wao',
    ],
    'Maguindanao del Norte' => [
        'Barira', 'Buldon', 'Datu Blah T. Sinsuat', 'Datu Odin Sinsuat',
        'Kabuntalan', 'Matanog', 'Northern Kabuntalan', 'Parang',
        'Sultan Kudarat', 'Sultan Mastura', 'Upi',
    ],
    'Maguindanao del Sur' => [
        'Ampatuan', 'Buluan', 'Datu Abdullah Sangki', 'Datu Anggal Midtimbang',
        'Datu Hoffer Ampatuan', 'Datu Montawal', 'Datu Paglas',
        'Datu Piang', 'Datu Salibo', 'Datu Saudi-Ampatuan', 'Datu Unsay',
        'General Salipada K. Pendatun', 'Guindulungan', 'Mamasapano',
        'Mangudadatu', 'Pagalungan', 'Paglat', 'Pandag', 'Rajah Buayan',
        'Shariff Aguak', 'Shariff Saydona Mustapha', 'South Upi',
        'Sultan sa Barongis', 'Talayan', 'Talitay',
    ],
    'Sulu' => [
        'Banguingui', 'Hadji Panglima Tahil', 'Indanan', 'Jolo', 'Kalingalan Caluang',
        'Lugus', 'Luuk', 'Maimbung', 'Old Panamao', 'Omar', 'Pandami',
        'Panglima Estino', 'Pangutaran', 'Parang', 'Pata', 'Patikul',
        'Siasi', 'Talipao', 'Tapul',
    ],
    'Tawi-Tawi' => [
        'Bongao', 'Languyan', 'Mapun', 'Panglima Sugala', 'Sapa-Sapa',
        'Sibutu', 'Simunul', 'Sitangkai', 'South Ubian', 'Tandubas',
        'Turtle Islands',
    ],
];
