with open('d:/MyGITRepository/BirdNET-Pi/scripts/api_v2.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

# Index 2075 is line 2076
# Index 2274 is line 2275
del lines[2075:2274]

with open('d:/MyGITRepository/BirdNET-Pi/scripts/api_v2.php', 'w', encoding='utf-8') as f:
    f.writelines(lines)
print('Repair finished.')
